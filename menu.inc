<?php

# $menuEntries is an array of menu entries for this plugin
# text - menu entry text
# type - status/content/output/help (main menu area)
# page - PHP file to display for this menu item
# wrap - 0/1 to hide or display the FPP header, menu, and footer on this page.
#        This does not apply for pages which begin with http:// or https://

# Comment out sub arrays to not display an item under a menu or add your own
# entries to display additional menu items such as the 3 items under the
# help menu in this example template.

# Check config for conditional menu entries
include_once __DIR__ . '/lib/config.php';
$watcherConfig = readPluginConfig();
$showLocalMetricsMenu = !empty($watcherConfig['collectdEnabled']);
$showConnectivityMenu = !empty($watcherConfig['connectivityCheckEnabled']);
$showMultiSyncMenu = !empty($watcherConfig['multiSyncMetricsEnabled']);
$showMultiSyncPingMenu = !empty($watcherConfig['multiSyncPingEnabled']);
$showFalconMonitorMenu = !empty($watcherConfig['falconMonitorEnabled']);
$showControlUIMenu = !empty($watcherConfig['controlUIEnabled']);

$menuEntries = Array(
	Array(
		'text' => 'Watcher - Config',
		'type' => 'content',
		'page' => 'configUI.php',
		'wrap' => 1
	),
	Array(
		'text' => 'Watcher - Home',
		'type' => 'help',
		'page' => 'https://github.com/agent462/fpp-plugin-watcher',
		'wrap' => 1
	)
);

// Add Local Metrics menu entry only if collectd is enabled
if ($showLocalMetricsMenu) {
	$helpIndex = count($menuEntries) - 1;
	array_splice($menuEntries, $helpIndex, 0, [
		Array(
			'text' => 'Watcher - Local Metrics',
			'type' => 'status',
			'page' => 'localMetricsUI.php',
			'wrap' => 1
		)
	]);
}

// Add Connectivity menu entry only if connectivity check is enabled
if ($showConnectivityMenu) {
	$helpIndex = count($menuEntries) - 1;
	array_splice($menuEntries, $helpIndex, 0, [
		Array(
			'text' => 'Watcher - Connectivity',
			'type' => 'status',
			'page' => 'connectivityUI.php',
			'wrap' => 1
		)
	]);
}

// Add Falcon Monitor menu entry only if enabled
if ($showFalconMonitorMenu) {
	$helpIndex = count($menuEntries) - 1;
	array_splice($menuEntries, $helpIndex, 0, [
		Array(
			'text' => 'Watcher - Falcon Monitor',
			'type' => 'status',
			'page' => 'falconMonitorUI.php',
			'wrap' => 1
		)
	]);
}

// Add Remote Systems menu entry only if enabled
if ($showMultiSyncMenu) {
	// Find the Help entry index (it may have shifted due to Falcon Monitor)
	$helpIndex = count($menuEntries) - 1;
	array_splice($menuEntries, $helpIndex, 0, [
		Array(
			'text' => 'Watcher - Remote Metrics',
			'type' => 'status',
			'page' => 'remoteMetricsUI.php',
			'wrap' => 1
		)
	]);
}

// Add Multi-Sync Ping menu entry only if enabled
if ($showMultiSyncPingMenu) {
	// Find the Help entry index (it may have shifted due to previous entries)
	$helpIndex = count($menuEntries) - 1;
	array_splice($menuEntries, $helpIndex, 0, [
		Array(
			'text' => 'Watcher - Remote Ping',
			'type' => 'status',
			'page' => 'remotePingUI.php',
			'wrap' => 1
		)
	]);
}

// Add Remote Control UI menu entry only if enabled
if ($showControlUIMenu) {
	// Find the Help entry index (it may have shifted due to previous entries)
	$helpIndex = count($menuEntries) - 1;
	array_splice($menuEntries, $helpIndex, 0, [
		Array(
			'text' => 'Watcher - Remote Control',
			'type' => 'status',
			'page' => 'remoteControlUI.php',
			'wrap' => 1
		)
	]);
}

##############################################################################
# Display the menu entries for this plugin.
#
# It is expected that two variables are already set:
# $plugin - contains the name of the current plugin directory/repoName
# $menu - contains the name of the menu section/type
foreach ($menuEntries as $entry)
{
	if ($entry['type'] != $menu)
		continue;

	if (preg_match('/^http.?:\/\//', $entry['page']))
	{
		printf("<li><a href='%s' target='_blank'>%s</a></li>\n",
			$entry['page'], $entry['text']);
	}
	else
	{
		$nopage = '';
		if (isset($entry['wrap']) && ($entry['wrap'] == 0))
			$nopage = '&nopage=1';

		printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n",
			$plugin, $entry['page'], $nopage, $entry['text']);
	}
}
?>
