function formatCurrency(amount) {
    return `KSh ${Number(amount).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    })}`;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit'
    });
}

function getAvatarInitials(name) {
    if (!name) return 'NA';
    const nameParts = name.trim().split(' ');
    if (nameParts.length > 1) {
        return (nameParts[0][0] + nameParts[nameParts.length - 1][0]).toUpperCase();
    }
    return nameParts[0].substring(0, 2).toUpperCase();
}

// Confirm logout
function confirmLogout() {
    return confirm('Are you sure you want to logout?');
}

// Add to window for global access
window.utils = {
    formatCurrency,
    formatDate,
    formatTime,
    getAvatarInitials,
    confirmLogout
};