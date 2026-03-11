<?php



class TaskManager extends IPSModule
{
    // ─────────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ─────────────────────────────────────────────────────────────────────────

    public function Create()
    {
        parent::Create();

        // Aufgaben werden als JSON-Array in einem Attribut gespeichert
        $this->RegisterAttributeString('Tasks', '[]');
        $this->RegisterAttributeInteger('NextID', 1);

        // Einstellungen
        $this->RegisterPropertyBoolean('DarkMode', true);
        $this->RegisterPropertyBoolean('ShowStats', true);
        $this->RegisterPropertyInteger('MaxCompletedVisible', 10);

        // Variablen
        $this->RegisterVariableString('TasksJson',    'Aufgaben (JSON)',      '',        1);
        $this->RegisterVariableString('HtmlBox',      'Aufgabenliste',        '~HTMLBox', 2);
        $this->RegisterVariableInteger('OpenTasks',   'Offene Aufgaben',      '',        3);
        $this->RegisterVariableInteger('OverdueTasks','Ueberfaellige Aufgaben','',       4);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook();
        $this->Refresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WebHook – empfängt POST aus der HTMLBox
    // ─────────────────────────────────────────────────────────────────────────

    public function ProcessHookData()
    {
        // CORS-Header damit Browser keinen Preflight-Fehler wirft
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            echo '';
            return;
        }

        $raw  = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);

        if (!is_array($data) || !isset($data['action'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ungueltige Anfrage']);
            return;
        }

        $action  = (string)$data['action'];
        $payload = isset($data['payload']) && is_array($data['payload'])
                   ? $data['payload'] : [];

        try {
            switch ($action) {
                case 'AddTask':
                    $this->AddTask($payload);
                    break;
                case 'UpdateTask':
                    $this->UpdateTask($payload);
                    break;
                case 'ToggleDone':
                    $this->ToggleDone($payload);
                    break;
                case 'DeleteTask':
                    $this->DeleteTask($payload);
                    break;
                case 'DeleteAllCompleted':
                    $this->DeleteAllCompleted();
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion: ' . $action]);
                    return;
            }
            $this->Refresh();
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            IPS_LogMessage('TaskManager', 'Hook-Fehler: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Öffentliche Methoden
    // ─────────────────────────────────────────────────────────────────────────

    public function TM_AddTask(string $Title, string $Info = '', string $Priority = 'normal', int $Due = 0): int
    {
        $id = $this->AddTask([
            'title'    => $Title,
            'info'     => $Info,
            'priority' => $Priority,
            'due'      => $Due,
        ]);
        $this->Refresh();
        return $id;
    }

    public function TM_DeleteAllCompleted()
    {
        $this->DeleteAllCompleted();
        $this->Refresh();
    }

    public function TM_Refresh()
    {
        $this->Refresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────────────────────────────────

    private function AddTask(array $Data): int
    {
        $tasks = $this->LoadTasks();
        $id    = $this->ReadAttributeInteger('NextID');
        $this->WriteAttributeInteger('NextID', $id + 1);

        $tasks[] = [
            'id'        => $id,
            'title'     => trim((string)($Data['title']    ?? '')),
            'info'      => trim((string)($Data['info']     ?? '')),
            'priority'  => $this->ValidPrio((string)($Data['priority'] ?? 'normal')),
            'due'       => (int)($Data['due'] ?? 0),
            'done'      => false,
            'createdAt' => time(),
        ];

        $this->SaveTasks($tasks);
        return $id;
    }

    private function UpdateTask(array $Data)
    {
        $id    = (int)($Data['id'] ?? -1);
        $tasks = $this->LoadTasks();
        foreach ($tasks as &$t) {
            if ((int)$t['id'] === $id) {
                $t['title']    = trim((string)($Data['title']    ?? $t['title']));
                $t['info']     = trim((string)($Data['info']     ?? $t['info']));
                $t['priority'] = $this->ValidPrio((string)($Data['priority'] ?? $t['priority']));
                $t['due']      = (int)($Data['due'] ?? $t['due']);
                break;
            }
        }
        $this->SaveTasks($tasks);
    }

    private function ToggleDone(array $Data)
    {
        $id    = (int)($Data['id']   ?? -1);
        $done  = (bool)($Data['done'] ?? false);
        $tasks = $this->LoadTasks();
        foreach ($tasks as &$t) {
            if ((int)$t['id'] === $id) {
                $t['done'] = $done;
                if ($done) {
                    $t['completedAt'] = time();
                } else {
                    unset($t['completedAt']);
                }
                break;
            }
        }
        $this->SaveTasks($tasks);
    }

    private function DeleteTask(array $Data)
    {
        $id    = (int)($Data['id'] ?? -1);
        $tasks = [];
        foreach ($this->LoadTasks() as $t) {
            if ((int)$t['id'] !== $id) {
                $tasks[] = $t;
            }
        }
        $this->SaveTasks($tasks);
    }

    private function DeleteAllCompleted()
    {
        $tasks = [];
        foreach ($this->LoadTasks() as $t) {
            if (empty($t['done'])) {
                $tasks[] = $t;
            }
        }
        $this->SaveTasks($tasks);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Refresh – alle Variablen aktualisieren
    // ─────────────────────────────────────────────────────────────────────────

    private function Refresh()
    {
        $tasks   = $this->LoadTasks();
        $now     = time();
        $open    = 0;
        $overdue = 0;

        foreach ($tasks as $t) {
            if (!empty($t['done'])) {
                continue;
            }
            $open++;
            $due = (int)($t['due'] ?? 0);
            if ($due > 0 && $due < $now) {
                $overdue++;
            }
        }

        // JSON-Variable mit allen Aufgaben
        $this->SetValue('TasksJson', json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // Zähler-Variablen
        $this->SetValue('OpenTasks',    $open);
        $this->SetValue('OverdueTasks', $overdue);

        // HTMLBox
        $hookUrl = $this->HookUrl();
        $this->SetValue('HtmlBox', $this->BuildHtml($tasks, $hookUrl));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTML
    // ─────────────────────────────────────────────────────────────────────────

    private function BuildHtml(array $Tasks, string $HookUrl): string
    {
        $dark      = (bool)$this->ReadPropertyBoolean('DarkMode');
        $showStats = (bool)$this->ReadPropertyBoolean('ShowStats');
        $maxDone   = (int)$this->ReadPropertyInteger('MaxCompletedVisible');

        $open = [];
        $done = [];
        foreach ($Tasks as $t) {
            if (!empty($t['done'])) {
                $done[] = $t;
            } else {
                $open[] = $t;
            }
        }

        usort($open, function ($a, $b) {
            return $this->SortScore($a) - $this->SortScore($b);
        });
        usort($done, function ($a, $b) {
            return (int)($b['completedAt'] ?? 0) - (int)($a['completedAt'] ?? 0);
        });
        if ($maxDone > 0) {
            $done = array_slice($done, 0, $maxDone);
        }

        $now    = time();
        $todayS = mktime(0, 0, 0);
        $todayE = mktime(23, 59, 59);

        $totalOverdue = 0;
        $totalToday   = 0;
        foreach ($open as $t) {
            $due = (int)($t['due'] ?? 0);
            if ($due > 0 && $due < $now) $totalOverdue++;
            if ($due >= $todayS && $due <= $todayE) $totalToday++;
        }

        $body = '';

        if ($showStats) {
            $body .= '<div class="tm-stats">'
                . '<div class="tm-stat tm-open"><div class="tm-val">' . count($open) . '</div><div class="tm-lbl">Offen</div></div>'
                . '<div class="tm-stat tm-overdue"><div class="tm-val">' . $totalOverdue . '</div><div class="tm-lbl">&#220;berf&#228;llig</div></div>'
                . '<div class="tm-stat tm-today"><div class="tm-val">' . $totalToday . '</div><div class="tm-lbl">Heute</div></div>'
                . '</div>';
        }

        $body .= '<button class="tm-btn tm-btn-add" onclick="tmOpenAdd()">+ Neue Aufgabe</button>';

        foreach ($open as $t) {
            $body .= $this->BuildRow($t, $now, $todayE);
        }
        if (!empty($done)) {
            $body .= '<div class="tm-divider">&#10003; Erledigt</div>';
            foreach ($done as $t) {
                $body .= $this->BuildRow($t, $now, $todayE);
            }
        }
        if (empty($open) && empty($done)) {
            $body .= '<div class="tm-empty">Keine Aufgaben &#8211; leg eine neue an!</div>';
        }

        $hookEsc = addslashes($HookUrl);

        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>' . $this->BuildCss($dark) . '</style>'
            . '</head><body>'
            . '<div class="tm-wrap">' . $body . '</div>'
            . $this->BuildModal()
            . '<script>'
            . 'var TM_HOOK="' . $hookEsc . '";'
            . $this->BuildJs()
            . '</script>'
            . '</body></html>';
    }

    private function BuildRow(array $T, int $Now, int $TodayE): string
    {
        $id    = (int)$T['id'];
        $title = htmlspecialchars((string)($T['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $info  = htmlspecialchars((string)($T['info']  ?? ''), ENT_QUOTES, 'UTF-8');
        $done  = !empty($T['done']);
        $prio  = (string)($T['priority'] ?? 'normal');
        $dueTs = (int)($T['due'] ?? 0);

        $infoHtml = $info !== ''
            ? '<div class="tm-info">' . $info . '</div>'
            : '';

        $badges = '';
        $pl = ['low' => 'Niedrig', 'normal' => 'Normal', 'high' => 'Hoch'];
        $badges .= '<span class="tm-badge tm-p-' . $prio . '">' . ($pl[$prio] ?? 'Normal') . '</span>';

        if ($dueTs > 0) {
            $dc = '';
            if ($dueTs < $Now) $dc = ' tm-overdue';
            elseif ($dueTs <= $TodayE) $dc = ' tm-today';
            $badges .= '<span class="tm-badge tm-due' . $dc . '">&#128197; ' . date('d.m.y H:i', $dueTs) . '</span>';
        }

        // Daten für Edit-Modal als JSON
        $editData = htmlspecialchars(json_encode([
            'id'       => $id,
            'title'    => (string)($T['title'] ?? ''),
            'info'     => (string)($T['info']  ?? ''),
            'priority' => $prio,
            'due'      => $dueTs,
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        return '<div class="tm-row' . ($done ? ' tm-done' : '') . '">'
            . '<input type="checkbox" class="tm-chk"'
            . ($done ? ' checked' : '')
            . ' onchange="tmToggle(' . $id . ',this.checked)">'
            . '<div class="tm-body" onclick="tmEdit(\'' . $editData . '\')">'
            . '<div class="tm-title">' . $title . '</div>'
            . $infoHtml
            . '<div class="tm-badges">' . $badges . '</div>'
            . '</div>'
            . '<button class="tm-del" onclick="tmDelete(' . $id . ')">&#x2715;</button>'
            . '</div>';
    }

    private function BuildModal(): string
    {
        return '<div id="tm-overlay" class="tm-overlay" onclick="tmClose()"></div>
<div id="tm-modal" class="tm-modal">
  <div class="tm-mhead">
    <span id="tm-mtitle">Neue Aufgabe</span>
    <button class="tm-mclose" onclick="tmClose()">&#x2715;</button>
  </div>
  <div class="tm-mbody">
    <input type="hidden" id="tm-id">
    <label class="tm-label">Titel *</label>
    <input class="tm-input" id="tm-title" type="text" placeholder="Titel der Aufgabe ...">
    <label class="tm-label">Beschreibung</label>
    <textarea class="tm-input tm-ta" id="tm-info" placeholder="Notiz (optional) ..."></textarea>
    <div class="tm-row2">
      <div>
        <label class="tm-label">Priorit&#228;t</label>
        <select class="tm-input" id="tm-prio">
          <option value="low">Niedrig</option>
          <option value="normal" selected>Normal</option>
          <option value="high">Hoch</option>
        </select>
      </div>
      <div>
        <label class="tm-label">F&#228;llig am</label>
        <input class="tm-input" id="tm-due" type="datetime-local">
      </div>
    </div>
  </div>
  <div class="tm-mfoot">
    <button class="tm-btn tm-btn-del" id="tm-btn-del" style="display:none" onclick="tmDeleteModal()">L&#246;schen</button>
    <button class="tm-btn" onclick="tmClose()">Abbrechen</button>
    <button class="tm-btn tm-btn-save" onclick="tmSave()">Speichern</button>
  </div>
</div>';
    }

    private function BuildJs(): string
    {
        return implode('', [
            'function tmPost(action, payload) {\n',
            '    fetch(TM_HOOK, {\n',
            '        method: \'POST\',\n',
            '        headers: {\'Content-Type\': \'application/json\'},\n',
            '        body: JSON.stringify({action: action, payload: payload || {}})\n',
            '    }).catch(function(e) { console.error(\'TM Fehler:\', e); });\n',
            '}\n',
            '\n',
            'function tmOpenAdd() {\n',
            '    document.getElementById(\'tm-id\').value = \'\';\n',
            '    document.getElementById(\'tm-title\').value = \'\';\n',
            '    document.getElementById(\'tm-info\').value = \'\';\n',
            '    document.getElementById(\'tm-prio\').value = \'normal\';\n',
            '    document.getElementById(\'tm-due\').value = \'\';\n',
            '    document.getElementById(\'tm-btn-del\').style.display = \'none\';\n',
            '    document.getElementById(\'tm-mtitle\').textContent = \'Neue Aufgabe\';\n',
            '    document.getElementById(\'tm-overlay\').classList.add(\'open\');\n',
            '    document.getElementById(\'tm-modal\').classList.add(\'open\');\n',
            '    setTimeout(function(){ document.getElementById(\'tm-title\').focus(); }, 80);\n',
            '}\n',
            '\n',
            'function tmEdit(s) {\n',
            '    var d; try { d = JSON.parse(s); } catch(e) { return; }\n',
            '    document.getElementById(\'tm-id\').value = d.id;\n',
            '    document.getElementById(\'tm-title\').value = d.title || \'\';\n',
            '    document.getElementById(\'tm-info\').value = d.info || \'\';\n',
            '    document.getElementById(\'tm-prio\').value = d.priority || \'normal\';\n',
            '    if (d.due > 0) {\n',
            '        var dt = new Date(d.due * 1000);\n',
            '        var p = function(n){ return String(n).padStart(2,\'0\'); };\n',
            '        document.getElementById(\'tm-due\').value =\n',
            '            dt.getFullYear()+\'-\'+p(dt.getMonth()+1)+\'-\'+p(dt.getDate())+\'T\'+p(dt.getHours())+\':\'+p(dt.getMinutes());\n',
            '    } else {\n',
            '        document.getElementById(\'tm-due\').value = \'\';\n',
            '    }\n',
            '    document.getElementById(\'tm-btn-del\').style.display = \'\';\n',
            '    document.getElementById(\'tm-mtitle\').textContent = \'Aufgabe bearbeiten\';\n',
            '    document.getElementById(\'tm-overlay\').classList.add(\'open\');\n',
            '    document.getElementById(\'tm-modal\').classList.add(\'open\');\n',
            '    setTimeout(function(){ document.getElementById(\'tm-title\').focus(); }, 80);\n',
            '}\n',
            '\n',
            'function tmClose() {\n',
            '    document.getElementById(\'tm-overlay\').classList.remove(\'open\');\n',
            '    document.getElementById(\'tm-modal\').classList.remove(\'open\');\n',
            '}\n',
            '\n',
            'function tmSave() {\n',
            '    var title = document.getElementById(\'tm-title\').value.trim();\n',
            '    if (!title) {\n',
            '        document.getElementById(\'tm-title\').style.borderColor = \'#ff5a5a\';\n',
            '        document.getElementById(\'tm-title\').focus();\n',
            '        return;\n',
            '    }\n',
            '    document.getElementById(\'tm-title\').style.borderColor = \'\';\n',
            '    var dueRaw = document.getElementById(\'tm-due\').value;\n',
            '    var payload = {\n',
            '        title:    title,\n',
            '        info:     document.getElementById(\'tm-info\').value.trim(),\n',
            '        priority: document.getElementById(\'tm-prio\').value,\n',
            '        due:      dueRaw ? Math.floor(new Date(dueRaw).getTime()/1000) : 0\n',
            '    };\n',
            '    var id = document.getElementById(\'tm-id\').value;\n',
            '    if (id) {\n',
            '        payload.id = parseInt(id, 10);\n',
            '        tmPost(\'UpdateTask\', payload);\n',
            '    } else {\n',
            '        tmPost(\'AddTask\', payload);\n',
            '    }\n',
            '    tmClose();\n',
            '}\n',
            '\n',
            'function tmToggle(id, done) { tmPost(\'ToggleDone\', {id:id, done:done}); }\n',
            'function tmDelete(id) { tmPost(\'DeleteTask\', {id:id}); }\n',
            'function tmDeleteModal() {\n',
            '    var id = document.getElementById(\'tm-id\').value;\n',
            '    if (id) { tmPost(\'DeleteTask\', {id:parseInt(id,10)}); tmClose(); }\n',
            '}\n',
            '\n',
            'document.addEventListener(\'keydown\', function(e) {\n',
            '    if (!document.getElementById(\'tm-modal\').classList.contains(\'open\')) return;\n',
            '    if (e.key === \'Escape\') tmClose();\n',
            '    if (e.key === \'Enter\' && document.activeElement.tagName !== \'TEXTAREA\') {\n',
            '        e.preventDefault(); tmSave();\n',
            '    }\n',
            '});\n',
        ]);
    }

    private function BuildCss(bool $Dark): string
    {
        $v = $Dark
            ? '--bg:#18191d;--card:#24252b;--text:#f0f0f0;--muted:rgba(240,240,240,.4);--border:rgba(255,255,255,.09);--accent:#00cdab;--red:#ff5a5a;--orange:#ffaa40;--sh:rgba(0,0,0,.5);--modal:#1e1f24;--inp:#14151a;--ov:rgba(0,0,0,.7);'
            : '--bg:#f0f2f5;--card:#fff;--text:#1a1a2e;--muted:rgba(26,26,46,.4);--border:rgba(0,0,0,.09);--accent:#00897b;--red:#d32f2f;--orange:#e65100;--sh:rgba(0,0,0,.1);--modal:#fff;--inp:#f0f2f5;--ov:rgba(0,0,0,.4);';

        return ':root{'.\$v.'}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:"Segoe UI",system-ui,sans-serif;font-size:14px;padding:10px;}
.tm-wrap{display:flex;flex-direction:column;gap:7px;}

/* Stats */
.tm-stats{display:flex;gap:8px;margin-bottom:2px;}
.tm-stat{flex:1;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:8px 4px;text-align:center;}
.tm-val{font-size:24px;font-weight:800;line-height:1;}
.tm-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-top:3px;}
.tm-open .tm-val{color:var(--accent);}
.tm-overdue .tm-val{color:var(--red);}
.tm-today .tm-val{color:var(--orange);}

/* Buttons */
.tm-btn{display:inline-flex;align-items:center;justify-content:center;border-radius:9px;border:1px solid var(--border);padding:8px 14px;font-size:13px;font-weight:600;background:var(--card);color:var(--text);cursor:pointer;font-family:inherit;white-space:nowrap;}
.tm-btn-add{width:100%;background:var(--accent);border-color:var(--accent);color:#fff;padding:10px;}
.tm-btn-save{background:var(--accent);border-color:var(--accent);color:#fff;}
.tm-btn-del{background:var(--red);border-color:var(--red);color:#fff;}

/* Rows */
.tm-row{display:flex;align-items:flex-start;gap:9px;background:var(--card);border:1px solid var(--border);border-radius:11px;padding:11px;}
.tm-row.tm-done{opacity:.45;}
.tm-chk{width:18px;height:18px;margin-top:2px;flex-shrink:0;cursor:pointer;accent-color:var(--accent);}
.tm-body{flex:1;min-width:0;cursor:pointer;}
.tm-title{font-weight:700;line-height:1.35;word-break:break-word;}
.tm-done .tm-title{text-decoration:line-through;}
.tm-info{font-size:12px;color:var(--muted);margin-top:2px;line-height:1.4;word-break:break-word;}
.tm-badges{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;}
.tm-badge{font-size:11px;padding:2px 7px;border-radius:999px;border:1px solid var(--border);color:var(--muted);}
.tm-p-high{border-color:var(--red);color:var(--red);background:rgba(255,90,90,.12);}
.tm-p-normal{border-color:var(--accent);color:var(--accent);background:rgba(0,205,171,.12);}
.tm-p-low{border-color:var(--muted);color:var(--muted);}
.tm-due.tm-overdue{border-color:var(--red);color:var(--red);background:rgba(255,90,90,.12);}
.tm-due.tm-today{border-color:var(--orange);color:var(--orange);background:rgba(255,170,64,.12);}
.tm-del{flex-shrink:0;background:transparent;border:none;color:var(--muted);cursor:pointer;font-size:13px;padding:2px 3px;border-radius:5px;line-height:1;}
.tm-del:hover{color:var(--red);}
.tm-divider{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);padding:6px 2px 2px;}
.tm-empty{text-align:center;color:var(--muted);padding:20px;font-size:13px;}

/* Modal */
.tm-overlay{display:none;position:fixed;inset:0;background:var(--ov);z-index:100;}
.tm-overlay.open{display:block;}
.tm-modal{display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);
  width:min(400px,calc(100vw - 24px));max-height:90vh;background:var(--modal);
  border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 32px var(--sh);
  z-index:101;flex-direction:column;overflow:hidden;}
.tm-modal.open{display:flex;}
.tm-mhead{display:flex;align-items:center;justify-content:space-between;
  padding:14px 16px 10px;border-bottom:1px solid var(--border);font-weight:700;font-size:15px;}
.tm-mclose{background:transparent;border:none;color:var(--muted);cursor:pointer;font-size:15px;}
.tm-mbody{padding:14px 16px;display:flex;flex-direction:column;gap:10px;overflow-y:auto;}
.tm-mfoot{display:flex;gap:7px;padding:10px 16px 14px;border-top:1px solid var(--border);justify-content:flex-end;}
.tm-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);display:block;margin-bottom:3px;}
.tm-input{background:var(--inp);border:1px solid var(--border);border-radius:7px;
  padding:8px 10px;font-size:13px;color:var(--text);outline:none;width:100%;
  font-family:inherit;transition:border-color .15s;}
.tm-input:focus{border-color:var(--accent);}
.tm-ta{resize:vertical;min-height:60px;line-height:1.4;}

    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function HookUrl(): string
    {
        return '/hook/taskmanager_' . $this->InstanceID;
    }

    private function RegisterHook()
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (empty($ids)) {
            IPS_LogMessage('TaskManager', 'Kein WebHook-Control gefunden!');
            return;
        }
        $hookId  = $ids[0];
        $uri     = $this->HookUrl();
        $hooks   = json_decode(IPS_GetProperty($hookId, 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $h) {
            if ($h['Hook'] === $uri && (int)$h['TargetID'] === $this->InstanceID) {
                return; // bereits registriert
            }
        }
        $hooks[] = ['Hook' => $uri, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookId, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookId);
        IPS_LogMessage('TaskManager', 'WebHook registriert: ' . $uri);
    }

    private function LoadTasks(): array
    {
        $data = json_decode($this->ReadAttributeString('Tasks'), true);
        return is_array($data) ? $data : [];
    }

    private function SaveTasks(array $Tasks)
    {
        $this->WriteAttributeString(
            'Tasks',
            json_encode(array_values($Tasks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function ValidPrio(string $P): string
    {
        return in_array($P, ['low', 'normal', 'high'], true) ? $P : 'normal';
    }

    private function SortScore(array $T): int
    {
        $map = ['high' => 0, 'normal' => 1000, 'low' => 2000];
        $s   = $map[$T['priority']] ?? 1000;
        $due = (int)($T['due'] ?? 0);
        return $due > 0 ? $s + ($due % 100000) : $s + 99999;
    }
}
