<?php
/**
 * SIMLedger - shared lookup helpers for pages.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * All terminals as id => name (respecting is_active when requested).
 *
 * @return array<int, string>
 */
function terminalOptions(bool $activeOnly = true): array
{
    $sql = 'SELECT id, name FROM terminals'
        . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name';

    $out = [];
    foreach ($GLOBALS['pdo']->query($sql) as $row) {
        $out[(int)$row['id']] = $row['name'];
    }

    return $out;
}

/**
 * All carriers as id => name.
 *
 * @return array<int, string>
 */
function carrierOptions(bool $activeOnly = true): array
{
    $sql = 'SELECT id, name FROM carriers'
        . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name';

    $out = [];
    foreach ($GLOBALS['pdo']->query($sql) as $row) {
        $out[(int)$row['id']] = $row['name'];
    }

    return $out;
}

/**
 * All driver agencies as id => name (RT = own company, plus outsourced
 * providers such as SMASCO / SRACO / TAMKEEN).
 *
 * @return array<int, string>
 */
function agencyOptions(bool $activeOnly = true): array
{
    $sql = 'SELECT id, name FROM agencies'
        . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name';

    $out = [];
    foreach ($GLOBALS['pdo']->query($sql) as $row) {
        $out[(int)$row['id']] = $row['name'];
    }

    return $out;
}

/**
 * SIM status => label map.
 *
 * @return array<string, string>
 */
function simStatusOptions(): array
{
    return [
        'available' => 'Available',
        'assigned'  => 'Assigned',
        'suspended' => 'Suspended',
        'lost'      => 'Lost',
        'damaged'   => 'Damaged',
        'retired'   => 'Retired',
    ];
}

/**
 * Driver status => label map.
 *
 * @return array<string, string>
 */
function driverStatusOptions(): array
{
    return [
        'active'      => 'Active',
        'resigned'    => 'Resigned',
        'terminated'  => 'Terminated',
        'inactive'    => 'Inactive',
        'transferred' => 'Transferred',
    ];
}

/**
 * Bootstrap badge for a SIM status.
 */
function simStatusBadge(string $status): string
{
    $label = simStatusOptions()[$status] ?? $status;

    return '<span class="badge badge-sim-' . e($status) . '">' . e($label) . '</span>';
}

/**
 * WHERE fragment limiting rows to the current user's terminal.
 * Returns [sqlFragment, params]. Empty fragment for all-terminals users.
 *
 * @return array{0: string, 1: array<int, mixed>}
 */
function terminalScopeSql(string $column): array
{
    $scope = currentTerminalId();

    if ($scope === null) {
        return ['', []];
    }

    return [' AND ' . $column . ' = ?', [$scope]];
}

/**
 * Terminal <select> that dispatchers cannot change (locked to their terminal).
 */
function terminalSelect(int|string|null $selected, bool $includeAll = false, string $name = 'terminal_id'): string
{
    // Accept string ids from PDO fetch() (stringified configs) as well.
    $selected = $selected !== null ? (int)$selected : null;
    $scope  = currentTerminalId();
    $out    = '';

    if ($scope !== null) {
        // Dispatcher: hidden fixed value - server still enforces via assertTerminalAccess.
        $label = terminalOptions()[$scope] ?? 'Terminal';
        $out .= '<input type="hidden" name="' . e($name) . '" value="' . $scope . '">'
            . '<input type="text" class="form-control" value="' . e($label) . '" disabled>';
        return $out;
    }

    $out .= '<select class="form-select" name="' . e($name) . '">';
    if ($includeAll) {
        $out .= '<option value="">All terminals</option>';
    } elseif ($selected === null) {
        // No silent default to the first terminal - force an explicit pick.
        $out .= '<option value="">Select terminal&hellip;</option>';
    }
    foreach (terminalOptions(false) as $id => $label) {
        $out .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>'
            . e($label) . '</option>';
    }
    $out .= '</select>';

    return $out;
}

/**
 * Checkbox-group filter control (multi-select) rendered as a Bootstrap
 * dropdown with checkboxes - the same pattern as the table Columns menu.
 * Toggle shows "All" (none checked = no filter = all records) or "n
 * selected". Inputs are named "<name>[]" so PHP receives an array;
 * filterValues() reads them back. Long option lists scroll. The toggle
 * uses data-bs-auto-close="outside" so ticking boxes keeps the menu open.
 */
function checkboxFilterGroup(string $name, array $options, array $selected): string
{
    $n     = count($selected);
    $state = $n === 0 ? 'All' : $n . ' selected';

    $html = '<div class="dropdown filter-dd">'
        . '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"'
        . ' data-bs-toggle="dropdown" data-bs-auto-close="outside"'
        . ' data-sl-count aria-expanded="false">'
        . '<span data-sl-text>' . e($state) . '</span>'
        . '<span class="sl-badge' . ($n > 0 ? '' : ' d-none') . '">' . $n . '</span>'
        . '</button>'
        . '<ul class="dropdown-menu">'
        . '<li class="sl-dd-head d-flex justify-content-between align-items-center">'
        . '<button type="button" class="btn btn-link p-0" data-sl-all>Select all</button>'
        . '<button type="button" class="btn btn-link p-0" data-sl-clear>Clear</button>'
        . '</li>';

    foreach ($options as $val => $label) {
        $val = (string)$val;
        if ($val === '') {
            continue;
        }
        $id = 'ff_' . e($name) . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $val);
        $html .= '<li><label class="dropdown-item" for="' . $id . '">'
            . '<input class="form-check-input me-1" type="checkbox" name="' . e($name) . '[]"'
            . ' value="' . e($val) . '" id="' . $id . '"'
            . (in_array($val, $selected, true) ? ' checked' : '') . '>'
            . '<span>' . e((string)$label) . '</span></label></li>';
    }

    return $html . '</ul></div>';
}

/**
 * Normalize a checkbox-filter GET parameter ("name[]" URLs) to an array of
 * trimmed, non-empty, de-duplicated strings. Legacy single-value links
 * (?status=assigned) are accepted too. Empty result = no filter.
 */
function filterValues(string $key): array
{
    $raw = $_GET[$key] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }

    $out = [];
    foreach ($raw as $v) {
        $v = trim((string)$v);
        if ($v !== '' && !in_array($v, $out, true)) {
            $out[] = $v;
        }
    }

    return $out;
}

