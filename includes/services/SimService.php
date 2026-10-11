<?php
/**
 * SIMLedger - SIM assignment transactions.
 *
 * Every state change runs inside a database transaction with row locks and
 * writes an audit record. Assignments are NEVER deleted or overwritten -
 * closures stamp returned_at / returned_by / return_reason only.
 *
 * WHO / WHAT / WHEN / WHERE / WHY is satisfied by the audit trail plus the
 * assignment rows themselves.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/functions.php';

final class SimService
{
    /** Return reasons accepted by the business (spec section 18). */
    public const RETURN_REASONS = [
        'resignation'  => 'Resignation',
        'transfer'     => 'Transfer',
        'vacation'     => 'Vacation',
        'replacement'  => 'Replacement',
        'lost_damaged' => 'Lost/Damaged',
        'sim_change'   => 'SIM Change',
        'other'        => 'Other',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // -------------------------------------------------------------------------
    // Reads used by validation + pages
    // -------------------------------------------------------------------------

    /**
     * The open assignment for a SIM, or null.
     */
    public function activeAssignmentForSim(int $simId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, d.badge_number, d.name AS driver_name, s.mobile_number, s.status AS sim_status
               FROM driver_sim_assignments a
               JOIN drivers d ON d.id = a.driver_id
               JOIN company_sims s ON s.id = a.sim_id
              WHERE a.sim_id = ? AND a.returned_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$simId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The open assignment for a driver, or null.
     */
    public function activeAssignmentForDriver(int $driverId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, s.mobile_number, s.status AS sim_status, s.carrier_id, c.name AS carrier_name
               FROM driver_sim_assignments a
               JOIN company_sims s ON s.id = a.sim_id
               JOIN carriers c ON c.id = s.carrier_id
              WHERE a.driver_id = ? AND a.returned_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$driverId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    // -------------------------------------------------------------------------
    // Issue SIM  (Available -> Assigned)
    // -------------------------------------------------------------------------

    /**
     * @throws RuntimeException with a user-facing message when validation fails
     * @return int the new assignment id
     */
    public function issue(int $driverId, int $simId, ?string $remarks, int $actingUserId): int
    {
        $this->pdo->beginTransaction();

        try {
            $driver = $this->lockDriver($driverId);
            $sim    = $this->lockSim($simId);

            $this->assertTerminalMatch((int)$driver['terminal_id'], (int)$sim['terminal_id'],
                'The driver and the SIM belong to different terminals.');

            if ((int)$driver['is_active'] !== 1 || $driver['status'] !== 'active') {
                throw new RuntimeException(
                    'This driver is not eligible for assignment (status: ' . $driver['status'] . ').'
                );
            }

            if ($sim['status'] !== 'available') {
                $current = $this->activeAssignmentForSim($simId);

                if ($current !== null) {
                    throw new RuntimeException(
                        'This SIM is currently assigned to ' . $current['driver_name']
                        . '. Return or transfer the SIM before assigning it.'
                    );
                }

                throw new RuntimeException(
                    'This SIM is not available (current status: ' . $sim['status'] . ').'
                );
            }

            $existing = $this->activeAssignmentForDriver($driverId);

            // One open SIM per driver - the database guard (driver_active_guard)
            // enforces the same rule, so no runtime switch exists.
            if ($existing !== null) {
                throw new RuntimeException(
                    'This driver already has an active company SIM. '
                    . 'Return or transfer the existing SIM before assigning another.'
                );
            }

            $issuedAt = date('Y-m-d H:i:s');

            $stmt = $this->pdo->prepare(
                'INSERT INTO driver_sim_assignments
                    (driver_id, sim_id, terminal_id, issued_at, issued_by, assignment_reason, remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $driverId, $simId, (int)$sim['terminal_id'], $issuedAt, $actingUserId,
                'issue', cleanStr($remarks, 255),
            ]);
            $assignmentId = (int)$this->pdo->lastInsertId();

            $this->setSimStatus($simId, 'assigned', $actingUserId);

            logAudit(
                'ISSUE_SIM', 'sim', $simId,
                ['status' => 'available', 'driver_id' => null],
                ['status' => 'assigned', 'driver_id' => $driverId,
                 'assignment_id' => $assignmentId, 'remarks' => $remarks],
                (int)$sim['terminal_id']
            );

            $this->pdo->commit();

            return $assignmentId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Return SIM  (Assigned -> Available), closes the assignment
    // -------------------------------------------------------------------------

    /**
     * @throws RuntimeException with a user-facing message when validation fails
     */
    public function returnSim(
        int $assignmentId,
        string $returnDate,
        string $returnReason,
        ?string $remarks,
        int $actingUserId,
        string $newStatus = 'available'
    ): void {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
            throw new RuntimeException('A valid return date is required.');
        }
        if (!isset(self::RETURN_REASONS[$returnReason])) {
            throw new RuntimeException('A valid return reason is required.');
        }

        $this->pdo->beginTransaction();

        try {
            $assignment = $this->lockAssignment($assignmentId);

            if ($assignment['returned_at'] !== null) {
                throw new RuntimeException('This assignment has already been closed.');
            }

            $this->closeAssignment($assignment, $returnDate, $returnReason, $remarks, $actingUserId);
            $this->setSimStatus((int)$assignment['sim_id'], $newStatus, $actingUserId);

            logAudit(
                $newStatus === 'available' ? 'RETURN_SIM' : 'MARK_' . strtoupper($newStatus),
                'sim', (int)$assignment['sim_id'],
                ['status' => 'assigned', 'assignment_id' => $assignmentId],
                ['status' => $newStatus, 'return_reason' => $returnReason,
                 'returned_at' => $returnDate, 'remarks' => $remarks],
                (int)$assignment['terminal_id']
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Transfer SIM  (close old assignment + open new one, same SIM, atomic)
    // -------------------------------------------------------------------------

    /**
     * @throws RuntimeException with a user-facing message when validation fails
     * @return int the new assignment id
     */
    public function transfer(
        int $assignmentId,
        int $newDriverId,
        string $returnDate,
        string $transferReason,
        ?string $remarks,
        int $actingUserId
    ): int {
        $this->pdo->beginTransaction();

        try {
            $assignment = $this->lockAssignment($assignmentId);

            if ($assignment['returned_at'] !== null) {
                throw new RuntimeException('This assignment has already been closed.');
            }
            if ((int)$assignment['driver_id'] === $newDriverId) {
                throw new RuntimeException('The SIM is already assigned to this driver.');
            }

            $newDriver = $this->lockDriver($newDriverId);

            if ((int)$newDriver['is_active'] !== 1 || $newDriver['status'] !== 'active') {
                throw new RuntimeException(
                    'The receiving driver is not eligible (status: ' . $newDriver['status'] . ').'
                );
            }

            // Note: cross-terminal transfer to an eligible driver is INTENTIONAL
            // for admins (spec section 49, scenario 3); dispatchers are blocked
            // earlier by assertTerminalAccess() in the controller for both the
            // assignment terminal and the receiving driver's terminal.

            $existing = $this->activeAssignmentForDriver($newDriverId);

            // One open SIM per driver (service + database level).
            if ($existing !== null) {
                throw new RuntimeException(
                    'This driver already has an active company SIM. '
                    . 'Return or transfer the existing SIM before assigning another.'
                );
            }

            // Close the previous driver's assignment (history preserved).
            $this->closeAssignment($assignment, $returnDate, 'transfer', $remarks, $actingUserId);

            // Open a new assignment on the SAME sim - it never disappears.
            $simId    = (int)$assignment['sim_id'];
            $sim      = $this->lockSim($simId);
            $issuedAt = date('Y-m-d H:i:s');

            $stmt = $this->pdo->prepare(
                'INSERT INTO driver_sim_assignments
                    (driver_id, sim_id, terminal_id, issued_at, issued_by, assignment_reason, remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $newDriverId, $simId, (int)$sim['terminal_id'], $issuedAt, $actingUserId,
                'transfer', cleanStr($remarks, 255),
            ]);
            $newAssignmentId = (int)$this->pdo->lastInsertId();

            $this->setSimStatus($simId, 'assigned', $actingUserId);

            logAudit(
                'TRANSFER_SIM', 'sim', $simId,
                ['driver_id' => (int)$assignment['driver_id'], 'assignment_id' => $assignmentId],
                ['driver_id' => $newDriverId, 'new_assignment_id' => $newAssignmentId,
                 'reason' => $transferReason, 'remarks' => $remarks],
                (int)$sim['terminal_id']
            );

            $this->pdo->commit();

            return $newAssignmentId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }


    // -------------------------------------------------------------------------
    // Replacement: close old assignment, damage old SIM, issue new SIM
    // -------------------------------------------------------------------------

    /**
     * @throws RuntimeException with a user-facing message when validation fails
     * @return int the new assignment id
     */
    public function replace(
        int $oldAssignmentId,
        int $newSimId,
        string $returnDate,
        string $reason,
        ?string $remarks,
        int $actingUserId
    ): int {
        $this->pdo->beginTransaction();

        try {
            $oldAssignment = $this->lockAssignment($oldAssignmentId);

            if ($oldAssignment['returned_at'] !== null) {
                throw new RuntimeException('This assignment has already been closed.');
            }

            $oldSimId = (int)$oldAssignment['sim_id'];

            if ($oldSimId === $newSimId) {
                throw new RuntimeException('Choose a different SIM for the replacement.');
            }

            $newSim = $this->lockSim($newSimId);

            if ($newSim['status'] !== 'available') {
                throw new RuntimeException(
                    'The replacement SIM must be Available (current status: ' . $newSim['status'] . ').'
                );
            }

            $this->assertTerminalMatch(
                (int)$oldAssignment['terminal_id'],
                (int)$newSim['terminal_id'],
                'The old and replacement SIMs belong to different terminals.'
            );

            // Close the old assignment and mark the old SIM damaged.
            $this->closeAssignment($oldAssignment, $returnDate, 'replacement', $remarks, $actingUserId);
            $this->setSimStatus($oldSimId, 'damaged', $actingUserId);

            // Issue the replacement to the same driver.
            $driverId = (int)$oldAssignment['driver_id'];
            $issuedAt = date('Y-m-d H:i:s');

            $stmt = $this->pdo->prepare(
                'INSERT INTO driver_sim_assignments
                    (driver_id, sim_id, terminal_id, issued_at, issued_by, assignment_reason, remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $driverId, $newSimId, (int)$newSim['terminal_id'], $issuedAt, $actingUserId,
                'replacement', cleanStr($remarks, 255),
            ]);
            $newAssignmentId = (int)$this->pdo->lastInsertId();

            $this->setSimStatus($newSimId, 'assigned', $actingUserId);

            logAudit(
                'REPLACE_SIM', 'sim', $oldSimId,
                ['status' => 'assigned', 'driver_id' => $driverId],
                ['old_sim_status' => 'damaged', 'new_sim_id' => $newSimId,
                 'new_assignment_id' => $newAssignmentId, 'reason' => $reason, 'remarks' => $remarks],
                (int)$oldAssignment['terminal_id']
            );

            $this->pdo->commit();

            return $newAssignmentId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }


    // -------------------------------------------------------------------------
    // Mark assigned SIM lost / damaged / suspended (closes the assignment)
    // -------------------------------------------------------------------------

    /**
     * @throws RuntimeException with a user-facing message when validation fails
     */
    public function markLostDamaged(
        int $simId,
        string $targetStatus,
        string $actionDate,
        ?string $reason,
        ?string $remarks,
        int $actingUserId
    ): void {
        if (!in_array($targetStatus, ['lost', 'damaged', 'suspended'], true)) {
            throw new RuntimeException('Invalid target status.');
        }

        $this->pdo->beginTransaction();

        try {
            $sim = $this->lockSim($simId);

            if ($sim['status'] !== 'assigned') {
                throw new RuntimeException('Only assigned SIMs can be marked ' . $targetStatus . '.');
            }

            $assignment = $this->activeAssignmentForSim($simId);

            if ($assignment === null) {
                throw new RuntimeException('No active assignment found for this SIM.');
            }

            $this->closeAssignment($assignment, $actionDate, 'lost_damaged', $remarks, $actingUserId);
            $this->setSimStatus($simId, $targetStatus, $actingUserId);

            logAudit(
                'MARK_' . strtoupper($targetStatus), 'sim', $simId,
                ['status' => 'assigned', 'driver_id' => (int)$assignment['driver_id']],
                ['status' => $targetStatus, 'reason' => $reason, 'remarks' => $remarks,
                 'action_date' => $actionDate],
                (int)$sim['terminal_id']
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Retire an unassigned SIM (open pool -> Retired), administrator only
    // -------------------------------------------------------------------------

    /**
     * @throws RuntimeException with a user-facing message when validation fails
     */
    public function retire(int $simId, ?string $reason, int $actingUserId): void
    {
        $this->pdo->beginTransaction();

        try {
            $sim = $this->lockSim($simId);

            if ($sim['status'] === 'assigned') {
                throw new RuntimeException(
                    'Return the SIM before retiring it (current: assigned).'
                );
            }

            $this->setSimStatus($simId, 'retired', $actingUserId);

            logAudit('RETIRE_SIM', 'sim', $simId,
                ['status' => $sim['status']],
                ['status' => 'retired', 'reason' => $reason],
                (int)$sim['terminal_id']);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }


    // -------------------------------------------------------------------------
    // Internal helpers (always called inside an open transaction)
    // -------------------------------------------------------------------------

    /**
     * Lock and return a driver row.
     */
    private function lockDriver(int $driverId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drivers WHERE id = ? FOR UPDATE');
        $stmt->execute([$driverId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new RuntimeException('Driver not found.');
        }

        return $row;
    }

    /**
     * Lock and return a SIM row.
     */
    private function lockSim(int $simId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM company_sims WHERE id = ? FOR UPDATE');
        $stmt->execute([$simId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new RuntimeException('SIM not found.');
        }

        return $row;
    }

    /**
     * Lock and return an assignment row.
     */
    private function lockAssignment(int $assignmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM driver_sim_assignments WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$assignmentId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new RuntimeException('Assignment not found.');
        }

        return $row;
    }

    /**
     * Close an assignment without deleting or overwriting its history.
     */
    private function closeAssignment(
        array $assignment,
        string $returnDate,
        string $returnReason,
        ?string $remarks,
        int $actingUserId
    ): void {
        // Accept Y-m-d or a full datetime from the form.
        $returnedAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)
            ? $returnDate . ' ' . date('H:i:s')
            : $returnDate;

        $stmt = $this->pdo->prepare(
            'UPDATE driver_sim_assignments
                SET returned_at = ?, returned_by = ?, return_reason = ?,
                    remarks = COALESCE(?, remarks)
              WHERE id = ? AND returned_at IS NULL'
        );
        $stmt->execute([
            $returnedAt, $actingUserId, $returnReason,
            cleanStr($remarks, 255),
            (int)$assignment['id'],
        ]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('This assignment has already been closed.');
        }
    }

    /**
     * Update SIM status + updated_by.
     */
    private function setSimStatus(int $simId, string $status, int $actingUserId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE company_sims SET status = ?, updated_by = ? WHERE id = ?'
        );
        $stmt->execute([$status, $actingUserId, $simId]);
    }

    /**
     * Guard: driver and SIM must belong to the same terminal.
     */
    private function assertTerminalMatch(int $a, int $b, string $message): void
    {
        if ($a !== $b) {
            throw new RuntimeException($message);
        }
    }

    }
