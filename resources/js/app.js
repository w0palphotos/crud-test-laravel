import Alpine from 'alpinejs';

// Alpine is global because the Blade templates reference it through x-data
// attributes, which have no import path. The small amount of interactivity here
// is the delete and delete-account confirmations, plus closing them on Escape.
window.Alpine = Alpine;

Alpine.start();
