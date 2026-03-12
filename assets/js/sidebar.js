document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const resourcesDropdown = document.getElementById('resourcesDropdown');
    const resourcesMenu = document.getElementById('resourcesMenu');
    const admissionDropdown = document.getElementById('admissionDropdown');
    const admissionMenu = document.getElementById('admissionMenu');
    
    const SIDEBAR_STATE_KEY = 'arawa-sidebar-collapsed';
    const RESOURCES_STATE_KEY = 'arawa-resources-open';
    const ADMISSION_STATE_KEY = 'arawa-admission-open';
    
    // Restore sidebar state
    function restoreSidebarState() {
        const isCollapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === 'true';
        if (isCollapsed && sidebar) {
            sidebar.classList.add('collapsed');
        }
    }
    
    // Restore dropdown states
    function restoreDropdownStates() {
        const resourcesOpen = localStorage.getItem(RESOURCES_STATE_KEY) === 'true';
        const admissionOpen = localStorage.getItem(ADMISSION_STATE_KEY) === 'true';
        
        if (resourcesOpen && resourcesMenu && resourcesDropdown) {
            resourcesMenu.classList.add('open');
            resourcesDropdown.classList.add('open');
        }
        
        if (admissionOpen && admissionMenu && admissionDropdown) {
            admissionMenu.classList.add('open');
            admissionDropdown.classList.add('open');
        }
    }
    
    // Toggle sidebar collapse
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem(SIDEBAR_STATE_KEY, isCollapsed);
            
            // Close dropdowns when collapsing
            if (isCollapsed) {
                if (resourcesMenu) resourcesMenu.classList.remove('open');
                if (resourcesDropdown) resourcesDropdown.classList.remove('open');
                if (admissionMenu) admissionMenu.classList.remove('open');
                if (admissionDropdown) admissionDropdown.classList.remove('open');
            }
        });
    }
    
    // Resources dropdown toggle
    if (resourcesDropdown && resourcesMenu) {
        resourcesDropdown.addEventListener('click', function(e) {
            e.preventDefault();
            if (!sidebar.classList.contains('collapsed')) {
                resourcesMenu.classList.toggle('open');
                resourcesDropdown.classList.toggle('open');
                const isOpen = resourcesMenu.classList.contains('open');
                localStorage.setItem(RESOURCES_STATE_KEY, isOpen);
            }
        });
    }
    
    // Admission dropdown toggle
    if (admissionDropdown && admissionMenu) {
        admissionDropdown.addEventListener('click', function(e) {
            e.preventDefault();
            if (!sidebar.classList.contains('collapsed')) {
                admissionMenu.classList.toggle('open');
                admissionDropdown.classList.toggle('open');
                const isOpen = admissionMenu.classList.contains('open');
                localStorage.setItem(ADMISSION_STATE_KEY, isOpen);
            }
        });
    }
    
    // Initialize states
    restoreSidebarState();
    restoreDropdownStates();
});