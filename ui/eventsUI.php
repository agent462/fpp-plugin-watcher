<?php
require_once __DIR__ . '/../classes/autoload.php';
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';

use Watcher\Http\ApiClient;
use Watcher\UI\ViewHelpers;

$config = readPluginConfig();
$localSystem = ApiClient::getInstance()->get('http://127.0.0.1/api/fppd/status', 5) ?: [];
$access = ViewHelpers::checkDashboardAccess($config, $localSystem, 'mqttMonitorEnabled');

ViewHelpers::renderCSSIncludes(true);
?>

<?php if (!ViewHelpers::renderAccessError($access)): ?>
<div class="metricsContainer" data-watcher-page="eventsUI">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #212529;">
            <i class="fas fa-rss"></i> FPP Events
        </h2>
        <span id="lastUpdate" style="font-size: 0.875rem; color: #6c757d;"></span>
    </div>

    <?php ViewHelpers::renderLoadingSpinner('Loading FPP events...'); ?>

    <div id="metricsContent" style="display: none;">
        <!-- Stats Bar -->
        <div class="statsBar">
            <div class="statItem">
                <div class="statLabel">Total Events</div>
                <div class="statValue" id="totalEvents">--</div>
            </div>
            <div class="statItem">
                <div class="statLabel">Sequences Played</div>
                <div class="statValue" id="sequencesPlayed">--</div>
            </div>
            <div class="statItem">
                <div class="statLabel">Playlists Started</div>
                <div class="statValue" id="playlistsStarted">--</div>
            </div>
            <div class="statItem">
                <div class="statLabel">Total Runtime</div>
                <div class="statValue" id="totalRuntime">--</div>
            </div>
        </div>

        <?php
        ViewHelpers::renderTimeRangeSelector(
            'timeRange',
            'page.refresh()',
            'Time Range:',
            [
                '24' => 'Last 24 Hours',
                '168' => 'Last 7 Days',
                '720' => 'Last 30 Days',
                '1440' => 'Last 60 Days'
            ],
            '24'
        );
        ?>

        <!-- Top Sequences -->
        <div class="chartCard">
            <div class="chartTitle">
                <span>
                    <i class="fas fa-play-circle"></i> Top Sequences
                </span>
            </div>
            <div id="topSequences" class="topItemsList">
                <p class="noData">No sequence data available</p>
            </div>
        </div>

        <!-- Event Timeline Chart -->
        <div class="chartCard">
            <div class="chartTitle">
                <span>
                    <i class="fas fa-chart-line"></i> Event Timeline
                </span>
            </div>
            <canvas id="timelineChart" style="max-height: 300px;"></canvas>
        </div>

        <!-- Recent Events Table -->
        <div class="chartCard">
            <div class="chartTitle">
                <span>
                    <i class="fas fa-list"></i> Recent Events
                    <span class="tierBadge" id="eventCount">0 events</span>
                </span>
            </div>
            <div class="tableWrapper">
                <table class="eventsTable" id="eventsTable">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Host</th>
                            <th>Event</th>
                            <th>Details</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody id="eventsTableBody">
                        <tr><td colspan="5" class="noData">No events found</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="showMoreContainer" style="text-align: center; margin-top: 1rem; display: none;">
                <button class="buttons btn-outline-secondary btn-sm" onclick="page.showMoreEvents()">
                    <i class="fas fa-chevron-down"></i> Show More
                </button>
            </div>
        </div>
    </div>

    <?php ViewHelpers::renderRefreshButton(); ?>
</div>

<style>
.topItemsList {
    padding: 0.5rem 0;
}
.topItem {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 1rem;
    border-bottom: 1px solid #e9ecef;
}
.topItem:last-child {
    border-bottom: none;
}
.topItem .rank {
    width: 24px;
    height: 24px;
    background: #6c757d;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
    flex-shrink: 0;
}
.topItem .name {
    flex: 1;
    font-weight: 500;
    color: #212529;
    margin-left: 0.75rem;
}
.topItem .count {
    background: #e7f3ff;
    color: #0066cc;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 600;
}
.tableWrapper {
    overflow-x: auto;
}
.eventsTable {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.eventsTable th,
.eventsTable td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}
.eventsTable th {
    background: #f8f9fa;
    font-weight: 600;
    color: #495057;
}
.eventsTable tr:hover {
    background: #f8f9fa;
}
.eventsTable .noData {
    text-align: center;
    color: #6c757d;
    padding: 2rem;
}
.eventBadge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}
.eventBadge.seq-start { background: #d4edda; color: #155724; }
.eventBadge.seq-stop { background: #f8d7da; color: #721c24; }
.eventBadge.pl-start { background: #cce5ff; color: #004085; }
.eventBadge.pl-stop { background: #e2e3e5; color: #383d41; }
.eventBadge.status { background: #fff3cd; color: #856404; }
.eventBadge.media-start { background: #e7e3ff; color: #5a47a8; }
.eventBadge.media-stop { background: #f3e8ff; color: #7c3aed; }
.eventBadge.warning { background: #fed7aa; color: #c2410c; }
</style>
<?php endif; ?>
