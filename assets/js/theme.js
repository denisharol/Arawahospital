(function() {
    const THEME_KEY = 'arawa-theme';
    
    function getStoredTheme() {
        return localStorage.getItem(THEME_KEY) || 'dark';
    }
    
    function setStoredTheme(theme) {
        localStorage.setItem(THEME_KEY, theme);
    }
    
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
    }
    
    function initTheme() {
        const theme = getStoredTheme();
        applyTheme(theme);
    }
    
    function toggleTheme() {
        const currentTheme = getStoredTheme();
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        setStoredTheme(newTheme);
        applyTheme(newTheme);
        return newTheme;
    }
    
    initTheme();
    
    window.themeManager = {
        toggle: toggleTheme,
        getCurrent: getStoredTheme,
        set: function(theme) {
            setStoredTheme(theme);
            applyTheme(theme);
        }
    };
})();