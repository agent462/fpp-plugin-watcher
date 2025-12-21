/**
 * multiSyncMetrics/issues.js - Issues panel and stats rendering
 *
 * Handles displaying:
 * - Stats summary (remotes, online, with plugin, issues)
 * - Issues panel with expandable details
 */

import { escapeHtml } from '../../core/utils.js';

/**
 * Update stats summary (Player Mode)
 * @param {number} total - Total remote count
 * @param {number} online - Online remote count
 * @param {number} withPlugin - Remotes with plugin installed
 * @param {number} issues - Issue count
 */
export function updateStats(total, online, withPlugin, issues) {
  const elements = {
    statRemotes: total,
    statOnline: online,
    statWithPlugin: withPlugin
  };

  for (const [id, value] of Object.entries(elements)) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  const issuesEl = document.getElementById('statIssues');
  if (issuesEl) {
    issuesEl.textContent = issues;
    issuesEl.className = 'msm-stat-value';
    if (issues > 0) issuesEl.classList.add('warning');
  }
}

/**
 * Update issues panel
 * @param {Array} issues - Array of issue objects
 */
export function updateIssues(issues) {
  const panel = document.getElementById('issuesPanel');
  const header = document.getElementById('issuesHeader');
  const list = document.getElementById('issuesList');
  const countEl = document.getElementById('issueCount');

  if (!panel || !list) return;

  if (!issues || issues.length === 0) {
    panel.classList.add('hidden');
    return;
  }

  panel.classList.remove('hidden');
  if (countEl) countEl.textContent = issues.length;

  const hasCritical = issues.some(i => i.severity >= 3);
  if (header) header.classList.toggle('critical', hasCritical);

  list.innerHTML = issues.map(issue => renderIssue(issue)).join('');
}

/**
 * Render a single issue item
 * @param {Object} issue - Issue object
 * @returns {string} HTML string for the issue
 */
function renderIssue(issue) {
  const iconClass = issue.severity === 3 ? 'critical' : (issue.severity === 2 ? 'warning' : 'info');
  const icon = issue.severity === 3 ? 'times-circle' : (issue.severity === 2 ? 'exclamation-triangle' : 'info-circle');

  let details = '';
  if (issue.expected && issue.actual) {
    details = `Expected: ${issue.expected}, Actual: ${issue.actual}`;
  } else if (issue.maxDrift !== undefined) {
    details = `Max: ${issue.maxDrift} frames, Avg: ${issue.avgDrift} frames`;
  } else if (issue.secondsSinceSync !== undefined) {
    details = `Last sync: ${issue.secondsSinceSync}s ago`;
  }

  return `
    <div class="msm-issue-item">
      <div class="msm-issue-icon ${iconClass}"><i class="fas fa-${icon}"></i></div>
      <div class="msm-issue-content">
        <div class="msm-issue-host">${escapeHtml(issue.host || 'Local')}</div>
        <div class="msm-issue-description">${escapeHtml(issue.description)}</div>
        ${details ? `<div class="msm-issue-details">${escapeHtml(details)}</div>` : ''}
      </div>
    </div>
  `;
}
