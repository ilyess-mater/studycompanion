<?php

declare(strict_types=1);

namespace App\Enum;

enum FocusViolationType: string
{
    case VisibilityChange = 'VISIBILITY_CHANGE';
    case FullscreenExit = 'FULLSCREEN_EXIT';
    case BlockedShortcut = 'BLOCKED_SHORTCUT';
    case WindowBlur = 'WINDOW_BLUR';
}
