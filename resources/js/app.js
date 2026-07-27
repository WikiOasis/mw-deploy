import Alpine from 'alpinejs';

import './echo';
import { deploymentMonitor } from './deployment-monitor';
import { deploymentWizard } from './deployment-wizard';

window.Alpine = Alpine;

Alpine.data('deploymentMonitor', deploymentMonitor);
Alpine.data('deploymentWizard', deploymentWizard);

Alpine.start();
