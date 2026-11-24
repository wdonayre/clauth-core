// Runs before paint (not deferred) so the console never flashes the wrong theme.
(function(){try{var m=localStorage.getItem('clauth-theme');if(m==='light'||m==='dark')document.documentElement.dataset.theme=m;document.documentElement.dataset.mode=m||'auto';}catch(e){}})();
