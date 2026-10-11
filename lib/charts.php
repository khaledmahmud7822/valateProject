<?php
/**
 * Metro Valet Parking — server-side SVG charts (no external libraries).
 *
 * chart_bars() / chart_line() take [label => value] and return an inline SVG
 * string using the app's design tokens. Empty input -> graceful empty state.
 * Defines functions only.
 */

/** Format a chart value: ints stay ints, big numbers get thousands separators. */
function chart_fmt_value($v): string {
    if (is_numeric($v) && floor((float) $v) == (float) $v) {
        return number_format((float) $v, 0);
    }
    return is_numeric($v) ? number_format((float) $v, 2) : (string) $v;
}

/** Shorten an axis label so bars don't collide. */
function chart_short_label(string $label, int $max = 10): string {
    $label = trim($label);
    return mb_strlen($label) > $max ? mb_substr($label, 0, $max - 1) . '…' : $label;
}

/** Shared empty state. */
function chart_empty(string $what = 'chart'): string {
    return '<div class="chart-empty" role="img" aria-label="No data available">No data yet.</div>';
}

/**
 * Vertical bar chart. $labels_values: ['Jan' => 12, 'Feb' => 30, ...].
 */
function chart_bars(array $labels_values, string $title = ''): string {
    if (empty($labels_values)) {
        return chart_empty();
    }
    $w = 600; $h = 250;
    $padL = 46; $padR = 14; $padT = 34; $padB = 46;
    $max = max($labels_values);
    $max = ($max > 0) ? (float) $max : 1.0;
    $n = count($labels_values);
    $slot = ($w - $padL - $padR) / $n;
    $bw = max(10, min(54, $slot * 0.55));
    $base = $h - $padB;
    $plotH = $base - $padT;

    $s = '<svg class="chart chart-bars" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($title !== '' ? $title : 'Bar chart') . '">';
    if ($title !== '') {
        $s .= '<text x="' . ($w / 2) . '" y="20" text-anchor="middle" font-size="14" font-weight="600" fill="#0A1A2F">' . e($title) . '</text>';
    }
    for ($g = 0; $g <= 4; $g++) {
        $y = $padT + $plotH * $g / 4;
        $val = $max * (1 - $g / 4);
        $s .= '<line x1="' . $padL . '" y1="' . round($y, 1) . '" x2="' . ($w - $padR) . '" y2="' . round($y, 1) . '" stroke="#E5E9F0" stroke-width="1"/>';
        $s .= '<text x="' . ($padL - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end" font-size="10" fill="#5B6774">' . e(chart_fmt_value($val)) . '</text>';
    }
    $i = 0;
    foreach ($labels_values as $label => $value) {
        $v = max(0, (float) $value);
        $bh = $plotH * ($v / $max);
        $x = $padL + $i * $slot + ($slot - $bw) / 2;
        $y = $base - $bh;
        $s .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round(max(1, $bh), 1)
            . '" rx="3" fill="#0A1A2F"><title>' . e((string) $label) . ': ' . e(chart_fmt_value($value)) . '</title></rect>';
        $s .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . round($y - 6, 1) . '" text-anchor="middle" font-size="10" fill="#1B2430">' . e(chart_fmt_value($value)) . '</text>';
        $s .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 14) . '" text-anchor="middle" font-size="10" fill="#5B6774">' . e(chart_short_label((string) $label)) . '</text>';
        $i++;
    }
    $s .= '<line x1="' . $padL . '" y1="' . $base . '" x2="' . ($w - $padR) . '" y2="' . $base . '" stroke="#C9A227" stroke-width="1.5"/>';
    return $s . '</svg>';
}

/**
 * Line chart. $labels_values: ['W1' => 5, 'W2' => 9, ...].
 */
function chart_line(array $labels_values, string $title = ''): string {
    if (empty($labels_values)) {
        return chart_empty();
    }
    $w = 600; $h = 250;
    $padL = 46; $padR = 20; $padT = 34; $padB = 46;
    $vals = array_values($labels_values);
    $labels = array_keys($labels_values);
    $n = count($vals);
    $max = max($vals);
    $min = min($vals);
    if ($max == $min) {
        $max += 1;
        $min = max(0, $min - 1);
    }
    $range = $max - $min;
    $base = $h - $padB;
    $plotH = $base - $padT;
    $plotW = $w - $padL - $padR;

    $px = function (int $i) use ($n, $padL, $plotW): float {
        return $n === 1 ? $padL + $plotW / 2 : $padL + $plotW * $i / ($n - 1);
    };
    $py = function ($v) use ($min, $range, $padT, $plotH): float {
        return $padT + $plotH * (1 - ((float) $v - $min) / $range);
    };

    $s = '<svg class="chart chart-line" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($title !== '' ? $title : 'Line chart') . '">';
    if ($title !== '') {
        $s .= '<text x="' . ($w / 2) . '" y="20" text-anchor="middle" font-size="14" font-weight="600" fill="#0A1A2F">' . e($title) . '</text>';
    }
    for ($g = 0; $g <= 4; $g++) {
        $y = $padT + $plotH * $g / 4;
        $val = $max - $range * $g / 4;
        $s .= '<line x1="' . $padL . '" y1="' . round($y, 1) . '" x2="' . ($w - $padR) . '" y2="' . round($y, 1) . '" stroke="#E5E9F0" stroke-width="1"/>';
        $s .= '<text x="' . ($padL - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end" font-size="10" fill="#5B6774">' . e(chart_fmt_value($val)) . '</text>';
    }

    $pts = [];
    for ($i = 0; $i < $n; $i++) {
        $pts[] = round($px($i), 1) . ',' . round($py($vals[$i]), 1);
    }
    // Area fill under the line.
    $area = implode(' ', $pts);
    $s .= '<polygon points="' . $padL . ',' . $base . ' ' . $area . ' ' . ($w - $padR) . ',' . $base . '" fill="#C9A227" opacity="0.15"/>';
    $s .= '<polyline points="' . $area . '" fill="none" stroke="#C9A227" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';

    // X labels (thinned when crowded).
    $step = max(1, (int) ceil($n / 10));
    for ($i = 0; $i < $n; $i++) {
        $cx = $px($i);
        $cy = $py($vals[$i]);
        $s .= '<circle cx="' . round($cx, 1) . '" cy="' . round($cy, 1) . '" r="4" fill="#0A1A2F" stroke="#ffffff" stroke-width="1.5">'
            . '<title>' . e((string) $labels[$i]) . ': ' . e(chart_fmt_value($vals[$i])) . '</title></circle>';
        if ($i % $step === 0) {
            $s .= '<text x="' . round($cx, 1) . '" y="' . ($h - 14) . '" text-anchor="middle" font-size="10" fill="#5B6774">' . e(chart_short_label((string) $labels[$i])) . '</text>';
        }
    }
    $s .= '<line x1="' . $padL . '" y1="' . $base . '" x2="' . ($w - $padR) . '" y2="' . $base . '" stroke="#C9A227" stroke-width="1.5"/>';
    return $s . '</svg>';
}
