import { startStimulusApp } from '@symfony/stimulus-bundle';
import FocusModeController from './controllers/focus_mode_controller.js';

const app = startStimulusApp();
app.register('focus-mode', FocusModeController);
