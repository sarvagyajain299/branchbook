

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();
import './slot-picker';
import './display';

if (document.getElementById('report-data')) {
    import('./reports-charts');
}
