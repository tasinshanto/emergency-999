<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$flash = pop_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(APP_NAME) ?> | Live Map</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="<?= h(url_path('assets/css/styles.css')) ?>">
    <script>window.APP_BASE = <?= json_encode(app_base_url()) ?>;</script>
    <style>
        /* Lock the page — only the sidebar form scrolls, never the page */
        html, body { height: 100%; overflow: hidden; }
        .public-map-section {
            position: fixed !important;
            top: 0; left: 0; right: 24rem; bottom: 0;
            z-index: 1;
        }
        .public-map { width: 100%; height: 100%; }
        .public-aside {
            position: fixed !important;
            top: 0; right: 0; bottom: 0;
            width: 24rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            z-index: 2;
            background: white;
            border-left: 1px solid #e2e8f0;
        }
        #report-form {
            flex: 1 1 0;
            min-height: 0;
            overflow-y: auto;
        }
        @media (max-width: 920px) {
            .public-map-section {
                position: relative !important;
                right: auto; bottom: auto;
                height: 55vh;
            }
            .public-aside {
                position: relative !important;
                width: 100%; height: auto;
                border-left: none;
                border-top: 1px solid #e2e8f0;
            }
        }
    </style>
</head>
<body class="bg-white text-slate-900">
<div class="public-shell">
    <section class="relative public-map-section">
        <div id="map" class="public-map"></div>
        <div class="pointer-events-none absolute left-4 top-4 z-[500] max-w-sm rounded border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur">
            <div class="flex items-center gap-3">
                <span class="grid h-11 w-11 place-items-center rounded bg-red-600 text-sm font-black text-white">999</span>
                <div>
                    <h1 class="text-lg font-black text-slate-950">Emergency 999</h1>
                    <p class="text-sm text-slate-600">Live incident monitoring for Dhaka response teams</p>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div class="rounded border border-slate-200 bg-slate-50 p-3">
                    <span class="block text-xs font-bold uppercase text-slate-500">Active incidents</span>
                    <span id="live-count" class="mt-1 block text-2xl font-black text-red-600">0</span>
                </div>
                <div class="rounded border border-slate-200 bg-slate-50 p-3">
                    <span class="block text-xs font-bold uppercase text-slate-500">Last update</span>
                    <span id="last-updated" class="mt-2 block text-sm font-bold text-slate-800">Waiting</span>
                </div>
            </div>
        </div>
    </section>

    <aside class="public-aside flex flex-col border-l border-slate-200 bg-white">
        <header class="border-b border-slate-200 p-4">
            <?php if ($flash): ?>
                <div class="mb-3 rounded border px-3 py-2 text-sm <?= h(status_badge_class($flash['type'] === 'error' ? 'rejected' : ($flash['type'] === 'success' ? 'resolved' : 'pending'))) ?>">
                    <?= h($flash['message']) ?>
                </div>
            <?php endif; ?>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-xs font-bold uppercase text-red-600">Emergency Control</p>
                    <h2 class="text-xl font-black text-slate-950">Report Incident</h2>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <?php if ($user): ?>
                        <span class="hidden text-sm text-slate-600 sm:inline"><?= h($user['name']) ?></span>
                        <a href="<?= h(url_path(role_home($user['role']))) ?>" class="btn-secondary">Dashboard</a>
                        <?php if ($user['role'] === 'admin'): ?>
                            <a href="<?= h(url_path('admin/reports.php')) ?>" class="btn-secondary">Reports</a>
                        <?php endif; ?>
                        <a href="<?= h(url_path('auth/logout.php')) ?>" class="btn-primary">Logout</a>
                    <?php else: ?>
                        <a href="<?= h(url_path('auth/login.php')) ?>" class="btn-secondary">Login</a>
                        <a href="<?= h(url_path('auth/register.php')) ?>" class="btn-primary">Register</a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <form id="report-form" class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
            <div id="form-status" hidden></div>

            <div>
                <label class="field-label" for="emergency_type_id">Emergency Type</label>
                <select class="field-select" id="emergency_type_id" name="emergency_type_id" required>
                    <option value="">Loading types...</option>
                </select>
            </div>

            <div>
                <span class="field-label">Services/Units Needed</span>
                <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                    <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                        <input type="checkbox" name="requested_unit_types[]" value="fire" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                        <span class="font-semibold text-slate-700">Fire Unit</span>
                    </label>
                    <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                        <input type="checkbox" name="requested_unit_types[]" value="ambulance" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                        <span class="font-semibold text-slate-700">Ambulance</span>
                    </label>
                    <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                        <input type="checkbox" name="requested_unit_types[]" value="police" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                        <span class="font-semibold text-slate-700">Police</span>
                    </label>
                    <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                        <input type="checkbox" name="requested_unit_types[]" value="rescue" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                        <span class="font-semibold text-slate-700">Rescue</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="field-label" for="title">Title</label>
                <input class="field-input" id="title" name="title" maxlength="160" required placeholder="Short incident title">
            </div>

            <div>
                <label class="field-label" for="severity">Severity</label>
                <select class="field-select" id="severity" name="severity" required>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                    <option value="low">Low</option>
                </select>
            </div>

            <div>
                <label class="field-label" for="description">Description</label>
                <textarea class="field-textarea" id="description" name="description" required placeholder="What happened?"></textarea>
            </div>

            <div>
                <label class="field-label" for="address">Address</label>
                <input class="field-input" id="address" name="address" required placeholder="Road, area, city" value="<?= h(($user ?? [])['address'] ?? '') ?>">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="latitude">Latitude</label>
                    <input class="field-input" id="latitude" name="latitude" required inputmode="decimal" placeholder="23.8103000">
                </div>
                <div>
                    <label class="field-label" for="longitude">Longitude</label>
                    <input class="field-input" id="longitude" name="longitude" required inputmode="decimal" placeholder="90.4125000">
                </div>
            </div>

            <button type="button" id="use-location" class="btn-secondary w-full">Use Current Location</button>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="reported_by_name">Reporter Name</label>
                    <input class="field-input" id="reported_by_name" name="reported_by_name" maxlength="120" required value="<?= h(($user ?? [])['name'] ?? '') ?>">
                </div>
                <div>
                    <label class="field-label" for="reported_by_phone">Phone</label>
                    <input class="field-input" id="reported_by_phone" name="reported_by_phone" maxlength="30" required value="<?= h(($user ?? [])['phone'] ?? '') ?>">
                </div>
            </div>

            <button type="submit" class="btn-primary w-full">Submit for Verification</button>
        </form>
    </aside>
</div>
<script src="<?= h(url_path('assets/js/public-live-incident-map.js')) ?>"></script>
</body>
</html>
