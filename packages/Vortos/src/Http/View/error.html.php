<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error <?= $statusCode ?> | Vortos</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">

    <style>
        :root {
            /* --- COLOR PALETTE: Vertos DARK --- */
            --bg-void: #050505;
            /* Deepest background */
            --bg-surface: #0f0f11;
            /* Card background */
            --bg-panel: #18181b;
            /* Inner panels */
            --bg-active: #27272a;
            /* Hover states */

            --border-dim: #27272a;
            --border-mid: #3f3f46;

            --text-bright: #ffffff;
            --text-main: #e4e4e7;
            --text-muted: #a1a1aa;
            --text-dim: #52525b;

            --brand-primary: #7c3aed;
            /* Vortos Purple */
            --signal-red: #dc2626;
            /* Error */
            --signal-amber: #d97706;
            /* Warning */
            --signal-blue: #2563eb;
            /* Info */
            --code-string: #a5f3fc;
            /* Cyan */
            --code-method: #ddd6fe;
            /* Light Purple */
        }

        /* --- GLOBAL RESET --- */
        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-void);
            color: var(--text-main);
            font-family: 'Inter', -apple-system, sans-serif;
            margin: 0;
            padding: 40px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
        }

        /* --- MAIN LAYOUT GRID --- */
        .console-wrapper {
            width: 100%;
            max-width: 1400px;
            /* Wide enough for long log lines */
            display: grid;
            gap: 24px;
        }

        /* =========================================================
           LAYER 1: PUBLIC INTERFACE
           Priority: High Visibility, Low Density
           ========================================================= */
        .layer-public {
            background: var(--bg-surface);
            border: 1px solid var(--border-dim);
            border-radius: 12px;
            padding: 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 6px solid var(--text-dim);
            /* Neutral status by default */
        }

        /* Dynamic Border Color based on status */
        .layer-public[data-status="500"] {
            border-left-color: var(--signal-red);
        }

        .layer-public[data-status="404"] {
            border-left-color: var(--signal-amber);
        }

        .public-content h1 {
            font-size: 36px;
            font-weight: 800;
            margin: 0 0 10px 0;
            color: var(--text-bright);
            letter-spacing: -1px;
        }

        .public-content p {
            margin: 0;
            font-size: 16px;
            color: var(--text-muted);
            max-width: 600px;
        }

        .status-badge {
            font-family: 'JetBrains Mono', monospace;
            font-size: 48px;
            font-weight: 700;
            color: var(--border-mid);
            /* Subtle watermark look */
            opacity: 0.5;
        }

        .action-bar {
            margin-top: 20px;
            display: flex;
            gap: 15px;
        }

        .btn {
            text-decoration: none;
            padding: 10px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--text-bright);
            color: var(--bg-void);
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border-dim);
        }

        .btn-secondary:hover {
            border-color: var(--text-main);
            color: var(--text-main);
        }


        /* =========================================================
           LAYER 2: THE CRASH REPORT (Developer Only)
           Priority: Critical Information
           ========================================================= */
        .layer-crash {
            background: var(--bg-surface);
            border: 1px solid var(--border-dim);
            border-top: 2px solid var(--signal-red);
            /* Red Top Border indicates Danger */
            border-radius: 8px;
            padding: 30px;
            position: relative;
        }

        .crash-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            text-transform: uppercase;
            color: var(--signal-red);
            font-weight: 700;
            letter-spacing: 1px;
        }

        .exception-title {
            font-family: 'JetBrains Mono', monospace;
            font-size: 28px;
            color: var(--text-bright);
            font-weight: 500;
            margin: 0 0 15px 0;
            word-break: break-all;
        }

        .exception-message {
            font-size: 18px;
            color: var(--text-muted);
            line-height: 1.6;
            background: var(--bg-void);
            padding: 20px;
            border-radius: 6px;
            border-left: 4px solid var(--border-mid);
            font-family: 'JetBrains Mono', monospace;
        }

        .file-location {
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
        }

        .file-path {
            color: var(--signal-blue);
        }

        .line-number {
            background: var(--signal-amber);
            color: black;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 700;
        }


        /* =========================================================
           LAYER 3: FORENSICS GRID (Developer Only)
           Layout: Asymmetrical Grid (Stack Trace gets 70% width)
           ========================================================= */
        .layer-forensics {
            display: grid;
            grid-template-columns: 2.5fr 1fr;
            /* 2.5 parts Stack Trace, 1 part Env */
            gap: 24px;
            align-items: start;
            /* Prevent stretching */
        }

        .panel {
            background: var(--bg-surface);
            border: 1px solid var(--border-dim);
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .panel-head {
            background: var(--bg-panel);
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-dim);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-dim);
            letter-spacing: 0.5px;
            display: flex;
            justify-content: space-between;
        }

        /* --- STACK TRACE STYLING --- */
        .trace-container {
            max-height: 600px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--border-mid) var(--bg-surface);
        }

        .trace-step {
            display: flex;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-dim);
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            gap: 15px;
            transition: background 0.1s;
        }

        .trace-step:hover {
            background: var(--bg-panel);
        }

        .trace-step:last-child {
            border-bottom: none;
        }

        .step-index {
            color: var(--text-dim);
            width: 24px;
            text-align: right;
            flex-shrink: 0;
            user-select: none;
        }

        .step-content {
            flex-grow: 1;
        }

        .step-method {
            color: var(--code-method);
            display: block;
            margin-bottom: 4px;
        }

        .step-file {
            font-size: 12px;
            color: var(--text-dim);
        }

        .step-file em {
            color: var(--text-muted);
            font-style: normal;
        }


        /* --- ENVIRONMENT TABLE STYLING --- */
        .env-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
        }

        .env-row {
            border-bottom: 1px solid var(--border-dim);
        }

        .env-row:last-child {
            border-bottom: none;
        }

        .env-key {
            padding: 10px 15px;
            color: var(--text-muted);
            width: 35%;
            vertical-align: top;
        }

        .env-val {
            padding: 10px 15px;
            color: var(--code-string);
            word-break: break-all;
        }

        /* --- CODE SNIPPET VIEWER --- */
        .code-viewer {
            background: #0d1117;
            /* GitHub Dark Dimmed */
            border: 1px solid var(--border-dim);
            border-radius: 8px;
            margin-top: 20px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            overflow-x: auto;
            padding: 10px 0;
        }

        .code-line {
            display: flex;
            line-height: 1.5;
        }

        /* Highlight the line where error happened */
        .code-line.is-error {
            background: rgba(220, 38, 38, 0.2);
            /* Red highlight */
            color: #fff;
        }

        .line-num {
            width: 50px;
            text-align: right;
            padding-right: 15px;
            color: #4b5563;
            user-select: none;
            border-right: 1px solid #30363d;
            background: #161b22;
        }

        .line-content {
            padding-left: 15px;
            color: #c9d1d9;
            white-space: pre;
            /* Keep indentation */
        }

        /* --- RESPONSIVE ADJUSTMENTS --- */
        @media (max-width: 1000px) {
            .layer-forensics {
                grid-template-columns: 1fr;
            }

            /* Stack vertically on small screens */
            .layer-public {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }

            .status-badge {
                order: -1;
            }

            /* Move number to top on mobile */
        }
    </style>
</head>

<body>

    <div class="console-wrapper">

        <div class="layer-public" data-status="<?= $statusCode ?>">
            <div class="public-content">
                <h1>
                    <?= ($statusCode >= 500) ? 'Internal System Error' : 'Page Not Found' ?>
                </h1>
                <p><?= htmlspecialchars($message) ?></p>

                <div class="action-bar">
                    <a href="/" class="btn btn-primary">Return Home</a>
                    <a href="http://localhost:3000/docs" class="btn btn-secondary">Documentation</a>
                </div>
            </div>
            <div class="status-badge">
                <?= $statusCode ?>
            </div>
        </div>

        <?php if (isset($isDebug) && $isDebug && isset($exception)):
            $h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ?>

            <div class="layer-crash">
                <div class="crash-header">
                    <span>Exception Thrown</span>
                    <span>PHP <?= phpversion() ?></span>
                </div>

                <div class="exception-title">
                    <?= $h(get_class($exception)) ?>
                </div>

                <div class="exception-message">
                    <?= $h($exception->getMessage()) ?>
                </div>

                <div class="file-location">
                    <span>ORIGIN:</span>
                    <span class="file-path"><?= $h($exception->getFile()) ?></span>
                    <span class="line-number"><?= $h($exception->getLine()) ?></span>
                </div>

                <div class="code-viewer">
                    <?php foreach ($codeSnippet as $lineNumber => $codeLine): ?>
                        <?php
                        $realLine = $lineNumber + 1;
                        $isError = ($realLine === $exception->getLine());
                        ?>
                        <div class="code-line <?= $isError ? 'is-error' : '' ?>">
                            <div class="line-num"><?= $realLine ?></div>
                            <div class="line-content"><?= htmlspecialchars($codeLine) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="layer-forensics">

                <div class="panel">
                    <div class="panel-head">
                        <span>Execution Stack</span>
                        <span><?= count($exception->getTrace()) ?> Frames</span>
                    </div>
                    <div class="trace-container">
                        <?php foreach ($exception->getTrace() as $i => $step): ?>
                            <div class="trace-step">
                                <div class="step-index"><?= $i ?></div>
                                <div class="step-content">
                                    <span class="step-method">
                                        <?= isset($step['class']) ? $h($step['class']) . $h($step['type']) : '' ?><?= $h($step['function']) ?>()
                                    </span>
                                    <?php if (isset($step['file'])): ?>
                                        <div class="step-file">
                                            <?= $h($step['file']) ?> : <em><?= $h($step['line']) ?></em>
                                        </div>
                                    <?php else: ?>
                                        <div class="step-file">[Internal PHP Function]</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">Request Environment</div>
                    <table class="env-table">
                        <tr class="env-row">
                            <td class="env-key">Request URI</td>
                            <td class="env-val"><?= $h($_SERVER['REQUEST_URI'] ?? 'N/A') ?></td>
                        </tr>
                        <tr class="env-row">
                            <td class="env-key">Method</td>
                            <td class="env-val"><?= $h($_SERVER['REQUEST_METHOD'] ?? 'CLI') ?></td>
                        </tr>
                        <tr class="env-row">
                            <td class="env-key">App Env</td>
                            <td class="env-val" style="color: var(--signal-amber);">
                                <?= $h($_ENV['APP_ENV'] ?: 'dev') ?>
                            </td>
                        </tr>
                        <tr class="env-row">
                            <td class="env-key">Memory</td>
                            <td class="env-val"><?= round(memory_get_usage() / 1024 / 1024, 2) ?> MB</td>
                        </tr>
                        <tr class="env-row">
                            <td class="env-key">Vortos Ver</td>
                            <td class="env-val">1.0.0-alpha</td>
                        </tr>
                        <tr class="env-row">
                            <td class="env-key">Server Time</td>
                            <td class="env-val"><?= date('H:i:s') ?> UTC</td>
                        </tr>
                    </table>
                </div>

            </div>
        <?php endif; ?>

    </div>

</body>

</html>