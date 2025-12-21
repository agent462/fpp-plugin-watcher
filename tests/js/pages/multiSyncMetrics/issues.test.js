/**
 * Tests for multiSyncMetrics/issues.js
 */

import { updateStats, updateIssues } from '@/pages/multiSyncMetrics/issues.js';

describe('multiSyncMetrics/issues', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <div id="statRemotes"></div>
      <div id="statOnline"></div>
      <div id="statWithPlugin"></div>
      <div id="statIssues" class="msm-stat-value"></div>
      <div id="issuesPanel" class="hidden">
        <div id="issuesHeader"></div>
        <div id="issueCount"></div>
        <div id="issuesList"></div>
      </div>
    `;
  });

  describe('updateStats', () => {
    test('updates all stat elements', () => {
      updateStats(10, 8, 6, 2);

      expect(document.getElementById('statRemotes').textContent).toBe('10');
      expect(document.getElementById('statOnline').textContent).toBe('8');
      expect(document.getElementById('statWithPlugin').textContent).toBe('6');
      expect(document.getElementById('statIssues').textContent).toBe('2');
    });

    test('adds warning class when issues > 0', () => {
      updateStats(5, 4, 3, 2);

      const issuesEl = document.getElementById('statIssues');
      expect(issuesEl.classList.contains('warning')).toBe(true);
    });

    test('does not add warning class when issues = 0', () => {
      updateStats(5, 5, 5, 0);

      const issuesEl = document.getElementById('statIssues');
      expect(issuesEl.classList.contains('warning')).toBe(false);
    });
  });

  describe('updateIssues', () => {
    test('hides panel when no issues', () => {
      updateIssues([]);

      const panel = document.getElementById('issuesPanel');
      expect(panel.classList.contains('hidden')).toBe(true);
    });

    test('hides panel when issues is null', () => {
      updateIssues(null);

      const panel = document.getElementById('issuesPanel');
      expect(panel.classList.contains('hidden')).toBe(true);
    });

    test('shows panel and renders issues', () => {
      const issues = [
        { host: 'Remote1', description: 'Offline', severity: 3 },
        { host: 'Remote2', description: 'High drift', severity: 2 }
      ];

      updateIssues(issues);

      const panel = document.getElementById('issuesPanel');
      expect(panel.classList.contains('hidden')).toBe(false);
      expect(document.getElementById('issueCount').textContent).toBe('2');
    });

    test('adds critical class to header when critical issues exist', () => {
      const issues = [
        { host: 'Remote1', description: 'Critical issue', severity: 3 }
      ];

      updateIssues(issues);

      const header = document.getElementById('issuesHeader');
      expect(header.classList.contains('critical')).toBe(true);
    });

    test('does not add critical class when no critical issues', () => {
      const issues = [
        { host: 'Remote1', description: 'Warning', severity: 2 }
      ];

      updateIssues(issues);

      const header = document.getElementById('issuesHeader');
      expect(header.classList.contains('critical')).toBe(false);
    });

    test('renders issue with expected/actual details', () => {
      const issues = [
        { host: 'Remote1', description: 'Sequence mismatch', severity: 2, expected: 'seq1', actual: 'seq2' }
      ];

      updateIssues(issues);

      const list = document.getElementById('issuesList');
      expect(list.innerHTML).toContain('Expected: seq1, Actual: seq2');
    });

    test('renders issue with drift details', () => {
      const issues = [
        { host: 'Remote1', description: 'High drift', severity: 2, maxDrift: 15, avgDrift: 12 }
      ];

      updateIssues(issues);

      const list = document.getElementById('issuesList');
      expect(list.innerHTML).toContain('Max: 15 frames, Avg: 12 frames');
    });

    test('renders issue with sync timing details', () => {
      const issues = [
        { host: 'Remote1', description: 'Stale sync', severity: 2, secondsSinceSync: 60 }
      ];

      updateIssues(issues);

      const list = document.getElementById('issuesList');
      expect(list.innerHTML).toContain('Last sync: 60s ago');
    });

    test('escapes HTML in issue content', () => {
      const issues = [
        { host: '<script>alert("xss")</script>', description: 'Test <b>bold</b>', severity: 1 }
      ];

      updateIssues(issues);

      const list = document.getElementById('issuesList');
      expect(list.innerHTML).not.toContain('<script>');
      expect(list.innerHTML).not.toContain('<b>');
      expect(list.innerHTML).toContain('&lt;script&gt;');
    });

    test('uses "Local" when host is missing', () => {
      const issues = [
        { description: 'Local issue', severity: 1 }
      ];

      updateIssues(issues);

      const list = document.getElementById('issuesList');
      expect(list.innerHTML).toContain('Local');
    });
  });
});
