import { startStimulusApp } from '@symfony/stimulus-bundle';
import FocusModeController from './controllers/focus_mode_controller.js';
import ThemeController from './controllers/theme_controller.js';
import ShellController from './controllers/shell_controller.js';
import LiquidPressController from './controllers/liquid_press_controller.js';
import ChatActionsController from './controllers/chat_actions_controller.js';

const app = startStimulusApp();
app.register('focus-mode', FocusModeController);
app.register('theme', ThemeController);
app.register('shell', ShellController);
app.register('liquid-press', LiquidPressController);
app.register('chat-actions', ChatActionsController);
