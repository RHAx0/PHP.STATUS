<?php

$root_status_file = __DIR__ . '/status.json';
$disk_path = '/';
$root_max_age = 5;

function read_cpu_snapshot()
{
    $content = @file_get_contents('/proc/stat');

    if ($content === false)
    {
        return null;
    }

    $values = null;
    $threads = 0;

    foreach (explode("\n", $content) as $line)
    {
        if (preg_match('/^cpu\s+(.+)$/', $line, $matches))
        {
            $values = preg_split('/\s+/', trim($matches[1]));
        }
        elseif (preg_match('/^cpu[0-9]+\s/', $line))
        {
            $threads++;
        }
    }

    if ($values === null || count($values) < 4)
    {
        return null;
    }

    $total = 0.0;

    // Guest time is already included in user and nice.
    for ($index = 0; $index < 8; $index++)
    {
        if (isset($values[$index]))
        {
            $total += (float) $values[$index];
        }
    }

    $idle = (float) $values[3];

    if (isset($values[4]))
    {
        $idle += (float) $values[4];
    }

    return array(
        'total' => $total,
        'idle' => $idle,
        'threads' => $threads
    );
}

function read_cpu_usage()
{
    $first = read_cpu_snapshot();

    if ($first === null)
    {
        return array('percent' => null, 'threads' => null);
    }

    // Measure CPU usage over a short interval.
    usleep(100000);

    $second = read_cpu_snapshot();

    if ($second === null)
    {
        return array('percent' => null, 'threads' => null);
    }

    $total_delta = $second['total'] - $first['total'];
    $idle_delta = $second['idle'] - $first['idle'];
    $percent = null;

    if ($total_delta > 0 && $idle_delta >= 0)
    {
        $percent = 100 * ($total_delta - $idle_delta) / $total_delta;
        $percent = round(max(0, min(100, $percent)), 1);
    }

    return array(
        'percent' => $percent,
        'threads' => $second['threads']
    );
}

function read_ram_usage()
{
    $empty_result = array(
        'percent' => null,
        'used' => null,
        'total' => null
    );

    $content = @file_get_contents('/proc/meminfo');

    if ($content === false)
    {
        return $empty_result;
    }

    $memory = array();

    foreach (explode("\n", $content) as $line)
    {
        if (preg_match('/^([A-Za-z_]+):\s+([0-9]+)\s+kB/', $line, $matches))
        {
            $memory[$matches[1]] = (float) $matches[2] * 1024;
        }
    }

    if (!isset($memory['MemTotal']) || $memory['MemTotal'] <= 0)
    {
        return $empty_result;
    }

    $total = $memory['MemTotal'];

    if (isset($memory['MemAvailable']))
    {
        $available = $memory['MemAvailable'];
    }
    else
    {
        // Approximate available memory on older kernels.
        $available = isset($memory['MemFree']) ? $memory['MemFree'] : 0;
        $available += isset($memory['Buffers']) ? $memory['Buffers'] : 0;
        $available += isset($memory['Cached']) ? $memory['Cached'] : 0;
        $available += isset($memory['SReclaimable']) ? $memory['SReclaimable'] : 0;
        $available -= isset($memory['Shmem']) ? $memory['Shmem'] : 0;
    }

    $available = max(0, min($total, $available));
    $used = $total - $available;

    return array(
        'percent' => round(100 * $used / $total, 1),
        'used' => $used,
        'total' => $total
    );
}

function read_disk_usage($disk_path)
{
    $total = @disk_total_space($disk_path);
    $free = @disk_free_space($disk_path);

    if ($total === false || $free === false || $total <= 0)
    {
        return array(
            'percent' => null,
            'used' => null,
            'total' => null
        );
    }

    $used = max(0, min($total, $total - $free));

    return array(
        'percent' => round(100 * $used / $total, 1),
        'used' => $used,
        'total' => $total
    );
}

function read_counter($file_path)
{
    $content = @file_get_contents($file_path);

    if ($content === false)
    {
        return null;
    }

    $content = trim($content);

    if (!preg_match('/^[0-9]+$/D', $content))
    {
        return null;
    }

    return (float) $content;
}

function read_network($interfaces)
{
    $rx_total = 0.0;
    $tx_total = 0.0;
    $readable_interfaces = array();
    $skipped_interfaces = array();

    foreach ($interfaces as $interface_name)
    {
        $base_path = '/sys/class/net/' . $interface_name . '/statistics/';
        $rx = read_counter($base_path . 'rx_bytes');
        $tx = read_counter($base_path . 'tx_bytes');

        // An interface may disappear between discovery and reading.
        if ($rx === null || $tx === null)
        {
            $skipped_interfaces[] = $interface_name;
            continue;
        }

        $rx_total += $rx;
        $tx_total += $tx;
        $readable_interfaces[] = $interface_name;
    }

    sort($readable_interfaces, SORT_STRING);

    return array(
        'rx' => count($readable_interfaces) > 0 ? $rx_total : null,
        'tx' => count($readable_interfaces) > 0 ? $tx_total : null,
        'timestamp' => microtime(true),
        'interfaces' => $readable_interfaces,
        'skipped' => $skipped_interfaces
    );
}

function discover_interfaces()
{
    $interfaces = array();
    $paths = glob('/sys/class/net/*', GLOB_ONLYDIR);

    if ($paths !== false)
    {
        foreach ($paths as $path)
        {
            $interfaces[] = basename($path);
        }
    }

    sort($interfaces, SORT_STRING);

    return $interfaces;
}

function read_root_network($file_path, $max_age)
{
    $content = @file_get_contents($file_path);

    if ($content === false)
    {
        return null;
    }

    $data = json_decode($content, true);

    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE)
    {
        return null;
    }

    foreach (array('rx', 'tx', 'timestamp') as $field)
    {
        if (!isset($data[$field]))
        {
            return null;
        }

        if (!is_int($data[$field]) && !is_float($data[$field]))
        {
            return null;
        }

        $value = (float) $data[$field];

        if (!is_finite($value) || $value < 0 || floor($value) != $value)
        {
            return null;
        }
    }

    if ($data['timestamp'] <= 0)
    {
        return null;
    }

    $age = time() - $data['timestamp'];

    return array(
        'rx' => $data['rx'],
        'tx' => $data['tx'],
        'timestamp' => $data['timestamp'],
        'stale' => ($age > $max_age || $age < -$max_age)
    );
}

if (isset($_GET['api']) && $_GET['api'] === '1')
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');

    $cpu = read_cpu_usage();
    $ram = read_ram_usage();
    $disk = read_disk_usage($disk_path);
    $root = read_root_network($root_status_file, $root_max_age);

    if ($root !== null)
    {
        $network_mode = 'split';
        $network = read_network(array('eth0', 'lo'));

        // Do not present a partial sum as eth0 + lo.
        if (count($network['skipped']) > 0)
        {
            $network['rx'] = null;
            $network['tx'] = null;
        }
    }
    else
    {
        $network_mode = 'single';
        $network = read_network(discover_interfaces());
    }

    echo json_encode(array(
        'cpu' => $cpu['percent'],
        'cpu_threads' => $cpu['threads'],
        'ram_percent' => $ram['percent'],
        'ram_used' => $ram['used'],
        'ram_total' => $ram['total'],
        'disk_percent' => $disk['percent'],
        'disk_used' => $disk['used'],
        'disk_total' => $disk['total'],
        'www_rx' => $network['rx'],
        'www_tx' => $network['tx'],
        'www_timestamp' => $network['timestamp'],
        'root_rx' => $root !== null ? $root['rx'] : null,
        'root_tx' => $root !== null ? $root['tx'] : null,
        'root_timestamp' => $root !== null ? $root['timestamp'] : null,
        'root_stale' => $root !== null ? $root['stale'] : true,
        'network_mode' => $network_mode,
        'network_interfaces' => $network['interfaces'],
        'network_skipped' => $network['skipped']
    ), JSON_UNESCAPED_SLASHES);

    exit;
}

?>
<!doctype html>
<html lang="pl" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monitoring serwera</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body
        {
            background: #10141c;
            color: #e9eef7;
        }

        .monitor_card
        {
            height: 100%;
            background: #191f2b;
            border: 1px solid #2d3748;
            border-radius: 1rem;
        }

        .metric_value
        {
            font-size: 2.2rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .network_value
        {
            font-size: 1.25rem;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }

        .metric_label
        {
            color: #a9b7ca;
            font-size: 0.85rem;
            letter-spacing: 0.06rem;
        }

        .progress
        {
            height: 0.6rem;
            background: #303a4b;
        }

        .network_box
        {
            height: 100%;
            padding: 1rem;
            background: #121823;
            border-radius: 0.75rem;
        }
    </style>
</head>
<body>
<main class="container py-4 py-lg-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Monitoring serwera</h1>
            <div class="text-secondary">CPU · RAM · HDD · NETWORK</div>
        </div>

        <div class="text-end" aria-live="polite">
            <span id="api_status" class="badge text-bg-secondary">Łączenie…</span>
            <div id="last_update" class="small text-secondary mt-2">—</div>
        </div>
    </div>

    <div class="row g-4">
        <?php
        $resource_cards = array(
            'cpu' => 'CPU',
            'ram' => 'RAM',
            'disk' => 'HDD'
        );

        foreach ($resource_cards as $card_id => $card_title)
        {
        ?>
        <div class="col-12 col-md-4">
            <section class="monitor_card p-4">
                <h2 class="h6 metric_label"><?php echo $card_title; ?></h2>

                <div id="<?php echo $card_id; ?>_value" class="metric_value mb-2">—</div>
                <div id="<?php echo $card_id; ?>_details" class="small text-secondary mb-3">—</div>

                <div
                    id="<?php echo $card_id; ?>_progress"
                    class="progress"
                    role="progressbar"
                    aria-label="<?php echo $card_title; ?>"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        id="<?php echo $card_id; ?>_bar"
                        class="progress-bar bg-info"
                        style="width: 0%"
                    ></div>
                </div>
            </section>
        </div>
        <?php
        }
        ?>

        <?php
        $network_cards = array(
            'www' => 'NETWORK',
            'root' => 'ROOT NETWORK'
        );

        foreach ($network_cards as $card_id => $card_title)
        {
        ?>
        <div
            id="<?php echo $card_id; ?>_column"
            class="<?php echo $card_id === 'root' ? 'col-12 col-lg-6 d-none' : 'col-12'; ?>"
        >
            <section class="monitor_card p-4">
                <h2 id="<?php echo $card_id; ?>_title" class="h6 metric_label mb-3">
                    <?php echo $card_title; ?>
                </h2>

                <div class="row g-3">
                    <div class="col-6">
                        <div class="network_box">
                            <div class="metric_label mb-1">RX</div>
                            <div id="<?php echo $card_id; ?>_rx" class="network_value">—</div>
                            <div class="small text-secondary mt-3">Prędkość RX</div>
                            <div id="<?php echo $card_id; ?>_rx_speed" class="network_value text-info">—</div>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="network_box">
                            <div class="metric_label mb-1">TX</div>
                            <div id="<?php echo $card_id; ?>_tx" class="network_value">—</div>
                            <div class="small text-secondary mt-3">Prędkość TX</div>
                            <div id="<?php echo $card_id; ?>_tx_speed" class="network_value text-success">—</div>
                        </div>
                    </div>
                </div>

                <div id="<?php echo $card_id; ?>_note" class="small text-secondary mt-3">
                    Oczekiwanie na dane…
                </div>
            </section>
        </div>
        <?php
        }
        ?>
    </div>

    <p class="small text-secondary mt-4 mb-0">
        Odświeżanie co około 1 s. Liczniki w bajtach, prędkość w bajtach/s.
    </p>
</main>

<script>
'use strict';

var network_state =
{
    www: null,
    root: null
};

var current_network_key = null;

function set_text(element_id, value)
{
    document.getElementById(element_id).textContent = value;
}

function is_number(value)
{
    return typeof value === 'number' && Number.isFinite(value);
}

function format_bytes(value)
{
    if (!is_number(value) || value < 0)
    {
        return '—';
    }

    var units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    var unit_index = 0;

    while (value >= 1024 && unit_index < units.length - 1)
    {
        value /= 1024;
        unit_index++;
    }

    return value.toLocaleString('pl-PL',
    {
        minimumFractionDigits: unit_index === 0 ? 0 : 2,
        maximumFractionDigits: unit_index === 0 ? 0 : 2
    }) + ' ' + units[unit_index];
}

function format_speed(value)
{
    return is_number(value) && value >= 0 ? format_bytes(value) + '/s' : '—';
}

function update_resource(resource_id, percent, details)
{
    var valid = is_number(percent);
    var safe_percent = valid ? Math.max(0, Math.min(100, percent)) : 0;
    var progress = document.getElementById(resource_id + '_progress');
    var bar = document.getElementById(resource_id + '_bar');

    set_text(
        resource_id + '_value',
        valid ? safe_percent.toFixed(1).replace('.', ',') + '%' : '—'
    );

    set_text(resource_id + '_details', details);

    bar.style.width = safe_percent + '%';
    bar.className = 'progress-bar ' +
        (safe_percent >= 90 ? 'bg-danger' : safe_percent >= 75 ? 'bg-warning' : 'bg-info');

    if (valid)
    {
        progress.setAttribute('aria-valuenow', safe_percent.toFixed(1));
    }
    else
    {
        progress.removeAttribute('aria-valuenow');
    }
}

function clear_network_speed(network_id)
{
    set_text(network_id + '_rx_speed', '—');
    set_text(network_id + '_tx_speed', '—');
}

function update_network(network_id, rx, tx, timestamp, stale)
{
    set_text(network_id + '_rx', format_bytes(rx));
    set_text(network_id + '_tx', format_bytes(tx));

    if (!is_number(rx) || !is_number(tx) || !is_number(timestamp))
    {
        network_state[network_id] = null;
        clear_network_speed(network_id);
        return 'Brak dostępu do liczników.';
    }

    if (stale)
    {
        network_state[network_id] = null;
        clear_network_speed(network_id);
        return 'Nieaktualny status.json lub rozbieżność zegarów.';
    }

    var previous = network_state[network_id];

    if (previous !== null && timestamp === previous.timestamp)
    {
        // Keep the previous speed until a new ROOT sample arrives.
        return 'Oczekiwanie na kolejną próbkę pliku.';
    }

    var rx_speed = null;
    var tx_speed = null;

    if (previous !== null && timestamp > previous.timestamp)
    {
        var elapsed = timestamp - previous.timestamp;

        if (rx >= previous.rx && tx >= previous.tx)
        {
            rx_speed = (rx - previous.rx) / elapsed;
            tx_speed = (tx - previous.tx) / elapsed;
        }
    }

    network_state[network_id] =
    {
        rx: rx,
        tx: tx,
        timestamp: timestamp
    };

    set_text(network_id + '_rx_speed', format_speed(rx_speed));
    set_text(network_id + '_tx_speed', format_speed(tx_speed));

    if (previous === null)
    {
        return 'Oczekiwanie na drugą próbkę.';
    }

    if (rx_speed === null || tx_speed === null)
    {
        return 'Reset liczników lub czasu — nowy punkt odniesienia.';
    }

    return '';
}

function render_data(data)
{
    update_resource(
        'cpu',
        data.cpu,
        'Wątki logiczne: ' + (is_number(data.cpu_threads) ? data.cpu_threads : '—')
    );

    update_resource(
        'ram',
        data.ram_percent,
        format_bytes(data.ram_used) + ' / ' + format_bytes(data.ram_total)
    );

    update_resource(
        'disk',
        data.disk_percent,
        format_bytes(data.disk_used) + ' / ' + format_bytes(data.disk_total)
    );

    var split_mode = data.network_mode === 'split';
    var interfaces = data.network_interfaces;
    var network_key = data.network_mode + ':' + JSON.stringify(interfaces);

    // Reset baselines when switching mode or changing local interfaces.
    if (network_key !== current_network_key)
    {
        network_state.www = null;
        network_state.root = null;
        current_network_key = network_key;
        clear_network_speed('www');
        clear_network_speed('root');
    }

    document.getElementById('www_column').className =
        split_mode ? 'col-12 col-lg-6' : 'col-12';

    document.getElementById('root_column').className =
        split_mode ? 'col-12 col-lg-6' : 'col-12 col-lg-6 d-none';

    set_text('www_title', split_mode ? 'WWW NETWORK' : 'NETWORK');

    var www_message = update_network(
        'www',
        data.www_rx,
        data.www_tx,
        data.www_timestamp,
        false
    );

    var www_note = split_mode ?
        'Interfejsy: eth0 + lo.' :
        'Wszystkie interfejsy: ' + (interfaces.join(', ') || 'brak') + '.';

    if (data.network_skipped.length > 0)
    {
        www_note += ' Nie można odczytać: ' + data.network_skipped.join(', ') + '.';
    }

    if (www_message !== '')
    {
        www_note += ' ' + www_message;
    }

    set_text('www_note', www_note);

    if (split_mode)
    {
        var root_message = update_network(
            'root',
            data.root_rx,
            data.root_tx,
            data.root_timestamp,
            data.root_stale
        );

        var root_note = 'Próbka ROOT: ' +
            new Date(data.root_timestamp * 1000).toLocaleString('pl-PL') + '.';

        if (root_message !== '')
        {
            root_note += ' ' + root_message;
        }

        set_text('root_note', root_note);
    }
}

async function refresh_status()
{
    var started_at = performance.now();
    var controller = new AbortController();

    var timeout_id = window.setTimeout(function()
    {
        controller.abort();
    }, 5000);

    try
    {
        var api_url = new URL(window.location.href);
        api_url.searchParams.set('api', '1');

        var response = await fetch(api_url.toString(),
        {
            cache: 'no-store',
            credentials: 'same-origin',
            signal: controller.signal,
            headers:
            {
                'Accept': 'application/json'
            }
        });

        if (!response.ok)
        {
            throw new Error('HTTP ' + response.status);
        }

        var data = await response.json();

        if (
            data === null ||
            typeof data !== 'object' ||
            (data.network_mode !== 'single' && data.network_mode !== 'split') ||
            !Array.isArray(data.network_interfaces) ||
            !Array.isArray(data.network_skipped)
        )
        {
            throw new Error('Nieprawidłowa odpowiedź API');
        }

        render_data(data);

        document.getElementById('api_status').className = 'badge text-bg-success';
        set_text('api_status', 'API online');
        set_text('last_update', 'Aktualizacja: ' + new Date().toLocaleTimeString('pl-PL'));
    }
    catch (error)
    {
        document.getElementById('api_status').className = 'badge text-bg-danger';
        set_text('api_status', 'Błąd API');
        set_text('last_update', 'Brak aktualizacji — widoczne dane mogą być nieaktualne.');

        network_state.www = null;
        network_state.root = null;

        clear_network_speed('www');
        clear_network_speed('root');

        set_text('www_note', 'Brak połączenia z API.');
        set_text('root_note', 'Brak połączenia z API.');
    }
    finally
    {
        window.clearTimeout(timeout_id);

        // Avoid overlapping requests.
        var elapsed_ms = performance.now() - started_at;
        window.setTimeout(refresh_status, Math.max(100, 1000 - elapsed_ms));
    }
}

refresh_status();
</script>
</body>
</html>