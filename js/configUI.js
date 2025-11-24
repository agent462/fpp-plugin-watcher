// Add a test host dynamically
function AddTestHost() {
    const input = document.getElementById('newHostInput');
    const host = input.value.trim();

    if (!host) {
        alert('Please enter a hostname or IP address');
        return;
    }

    // Check for duplicates
    const existingHosts = Array.from(document.querySelectorAll('input[name="testHosts[]"]'))
        .map(el => el.value);

    if (existingHosts.includes(host)) {
        alert('This host is already in the list');
        return;
    }

    // Add to the list
    const container = document.getElementById('testHostsList');

    // Remove "no hosts" message if present
    const noHostsMsg = container.querySelector('[style*="text-align: center"]');
    if (noHostsMsg) {
        noHostsMsg.remove();
    }

    const div = document.createElement('div');
    div.className = 'testHostItem';
    div.innerHTML = `
        <span><strong>${EscapeHtml(host)}</strong></span>
        <input type="hidden" name="testHosts[]" value="${EscapeHtml(host)}">
        <button type="button" class="buttons btn-danger btn-sm" onclick="RemoveTestHost(this)">
            <i class="fas fa-trash"></i> Remove
        </button>
    `;
    container.appendChild(div);

    input.value = '';
}

// Remove a test host
function RemoveTestHost(button) {
    const item = button.closest('.testHostItem');
    item.remove();

    // Check if list is now empty
    const container = document.getElementById('testHostsList');
    if (container.children.length === 0) {
        container.innerHTML = '<div style="padding: 1rem; text-align: center; color: #6c757d;">No test hosts configured. Add at least one.</div>';
    }
}

// Escape HTML to prevent XSS
function EscapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

// Allow Enter key to add test host
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('newHostInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            AddTestHost();
        }
    });

    // Form validation before submit
    document.getElementById('watcherSettingsForm').addEventListener('submit', function(e) {
        const testHosts = document.querySelectorAll('input[name="testHosts[]"]');
        if (testHosts.length === 0) {
            e.preventDefault();
            alert('Please add at least one test host');
            return false;
        }
    });
});
