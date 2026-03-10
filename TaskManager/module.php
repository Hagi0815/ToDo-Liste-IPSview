<?php

declare(strict_types=1);

class TaskManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeString('Tasks', '[]');
        $this->RegisterAttributeInteger('NextID', 1);

        $this->RegisterPropertyInteger('MaxCompletedVisible', 10);
        $this->RegisterPropertyBoolean('ShowStats', true);
        $this->RegisterPropertyBoolean('ShowPriority', true);
        $this->RegisterPropertyBoolean('ShowDueDate', true);
        $this->RegisterPropertyBoolean('DarkMode', true);

        $this->RegisterVariableString('TaskListHtml', 'Aufgabenliste', '~HTMLBox', 1);
        $this->RegisterVariableInteger('OpenTasks', 'Offene Aufgaben', '', 2);
        $this->RegisterVariableInteger('OverdueTasks', 'Ueberfaellig', '', 3);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->Refresh();
    }

    public function RequestAction($Ident, $Value)
    {
        $data = json_decode($Value, true);
        if (!is_array($data)) {
            $data = [];
        }

        switch ($Ident) {
            case 'AddTask':
                $this->AddTask($data);
                break;
            case 'UpdateTask':
                $this->UpdateTask($data);
                break;
            case 'ToggleDone':
                $this->ToggleDone($data);
                break;
            case 'DeleteTask':
                $this->DeleteTask($data);
                break;
            case 'DeleteAllCompleted':
                $this->DeleteAllCompleted();
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->Refresh();
    }

    public function TM_AddTask(string $Title, string $Info = '', string $Priority = 'normal', int $Due = 0): int
    {
        $id = $this->AddTask(['title' => $Title, 'info' => $Info, 'priority' => $Priority, 'due' => $Due]);
        $this->Refresh();
        return $id;
    }

    public function TM_DeleteAllCompleted()
    {
        $this->DeleteAllCompleted();
        $this->Refresh();
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    private function AddTask(array $Data): int
    {
        $tasks = $this->LoadTasks();
        $id    = $this->ReadAttributeInteger('NextID');
        $this->WriteAttributeInteger('NextID', $id + 1);

        $tasks[] = [
            'id'        => $id,
            'title'     => trim((string)($Data['title'] ?? '')),
            'info'      => trim((string)($Data['info'] ?? '')),
            'priority'  => $this->ValidatePriority((string)($Data['priority'] ?? 'normal')),
            'due'       => (int)($Data['due'] ?? 0),
            'done'      => false,
            'createdAt' => time(),
        ];

        $this->SaveTasks($tasks);
        return $id;
    }

    private function UpdateTask(array $Data)
    {
        $id    = (int)($Data['id'] ?? 0);
        $tasks = $this->LoadTasks();
        foreach ($tasks as &$t) {
            if ((int)$t['id'] === $id) {
                $t['title']    = trim((string)($Data['title'] ?? $t['title']));
                $t['info']     = trim((string)($Data['info'] ?? $t['info']));
                $t['priority'] = $this->ValidatePriority((string)($Data['priority'] ?? $t['priority']));
                $t['due']      = (int)($Data['due'] ?? $t['due']);
                break;
            }
        }
        $this->SaveTasks($tasks);
    }

    private function ToggleDone(array $Data)
    {
        $id    = (int)($Data['id'] ?? 0);
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
        $id    = (int)($Data['id'] ?? 0);
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

    // ── Refresh ───────────────────────────────────────────────────────────────

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
        $this->SetValue('OpenTasks', $open);
        $this->SetValue('OverdueTasks', $overdue);
        $this->SetValue('TaskListHtml', $this->BuildHtml($tasks));
    }

    // ── HTML ──────────────────────────────────────────────────────────────────

    private function BuildHtml(array $Tasks): string
    {
        $iid       = $this->InstanceID;
        $dark      = (bool)$this->ReadPropertyBoolean('DarkMode');
        $showStats = (bool)$this->ReadPropertyBoolean('ShowStats');
        $showPrio  = (bool)$this->ReadPropertyBoolean('ShowPriority');
        $showDue   = (bool)$this->ReadPropertyBoolean('ShowDueDate');
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

        $totalOpen    = count($open);
        $totalOverdue = 0;
        $totalToday   = 0;
        foreach ($open as $t) {
            $due = (int)($t['due'] ?? 0);
            if ($due > 0 && $due < $now) {
                $totalOverdue++;
            }
            if ($due >= $todayS && $due <= $todayE) {
                $totalToday++;
            }
        }

        $body = '';

        if ($showStats) {
            $body .= '<div class="tm-stats">'
                . '<div class="tm-stat tm-stat-open"><div class="tm-stat-val">' . $totalOpen . '</div><div class="tm-stat-lbl">Offen</div></div>'
                . '<div class="tm-stat tm-stat-overdue"><div class="tm-stat-val">' . $totalOverdue . '</div><div class="tm-stat-lbl">&#220;berf&#228;llig</div></div>'
                . '<div class="tm-stat tm-stat-today"><div class="tm-stat-val">' . $totalToday . '</div><div class="tm-stat-lbl">Heute</div></div>'
                . '</div>';
        }

        $body .= $this->BuildFormHtml($iid);

        foreach ($open as $t) {
            $body .= $this->BuildRow($t, $showPrio, $showDue, $now, $todayS, $todayE);
        }
        if (!empty($done)) {
            $body .= '<div class="tm-section-header">Erledigt</div>';
            foreach ($done as $t) {
                $body .= $this->BuildRow($t, $showPrio, $showDue, $now, $todayS, $todayE);
            }
        }
        if (empty($open) && empty($done)) {
            $body .= '<div class="tm-empty">Keine Aufgaben &#8211; leg eine neue an!</div>';
        }

        return '<style>' . $this->BuildCss($dark) . '</style>'
            . $this->BuildJs($iid)
            . '<div class="tm-wrap">' . $body . '</div>';
    }

    private function BuildFormHtml(int $iid): string
    {
        return '
<div class="tm-add-bar">
  <button class="tm-btn tm-btn-primary tm-btn-full" onclick="tmOpenAdd()">+ Neue Aufgabe</button>
</div>
<div class="tm-backdrop" id="tm-backdrop" onclick="tmCloseModal()"></div>
<div class="tm-modal" id="tm-modal">
  <div class="tm-modal-header">
    <span id="tm-modal-title">Neue Aufgabe</span>
    <button class="tm-modal-close" onclick="tmCloseModal()">&#x2715;</button>
  </div>
  <div class="tm-modal-body">
    <input type="hidden" id="tm-edit-id" value="" />
    <div class="tm-field">
      <label class="tm-label">Titel *</label>
      <input class="tm-input" id="tm-f-title" type="text" placeholder="Aufgabentitel ..." autocomplete="off" />
    </div>
    <div class="tm-field">
      <label class="tm-label">Beschreibung</label>
      <textarea class="tm-input tm-textarea" id="tm-f-info" placeholder="Notiz (optional) ..."></textarea>
    </div>
    <div class="tm-field-row">
      <div class="tm-field">
        <label class="tm-label">Priorit&#228;t</label>
        <select class="tm-input" id="tm-f-prio">
          <option value="low">Niedrig</option>
          <option value="normal" selected>Normal</option>
          <option value="high">Hoch</option>
        </select>
      </div>
      <div class="tm-field">
        <label class="tm-label">F&#228;llig am</label>
        <input class="tm-input" id="tm-f-due" type="datetime-local" />
      </div>
    </div>
    <div id="tm-status" style="font-size:12px;min-height:18px;color:red;margin-top:4px;"></div>
  </div>
  <div class="tm-modal-footer">
    <button class="tm-btn tm-btn-danger" id="tm-del-btn" onclick="tmDeleteFromModal()" style="display:none">L&#246;schen</button>
    <button class="tm-btn" onclick="tmCloseModal()">Abbrechen</button>
    <button class="tm-btn tm-btn-primary" id="tm-save-btn" onclick="tmSaveModal()">Speichern</button>
  </div>
</div>';
    }

    private function BuildRow(array $T, bool $ShowPrio, bool $ShowDue, int $Now, int $TodayS, int $TodayE): string
    {
        $id    = (int)$T['id'];
        $title = htmlspecialchars((string)($T['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $info  = htmlspecialchars((string)($T['info'] ?? ''), ENT_QUOTES, 'UTF-8');
        $done  = !empty($T['done']);
        $prio  = (string)($T['priority'] ?? 'normal');
        $dueTs = (int)($T['due'] ?? 0);

        $chk = '<input type="checkbox" class="tm-chk" '
            . ($done ? 'checked' : '')
            . ' onchange="tmToggle(' . $id . ', this.checked)" />';

        $infoHtml = $info !== '' ? '<div class="tm-info">' . $info . '</div>' : '';

        $badges = '';
        if ($ShowPrio) {
            $pl     = ['low' => 'Niedrig', 'normal' => 'Normal', 'high' => 'Hoch'];
            $badges .= '<span class="tm-badge tm-badge-' . $prio . '">' . ($pl[$prio] ?? 'Normal') . '</span>';
        }
        if ($ShowDue && $dueTs > 0) {
            $dc = '';
            if ($dueTs < $Now) {
                $dc = ' tm-due-overdue';
            } elseif ($dueTs <= $TodayE) {
                $dc = ' tm-due-today';
            }
            $badges .= '<span class="tm-badge tm-due-badge' . $dc . '">&#128197; ' . date('d.m.Y H:i', $dueTs) . '</span>';
        }

        $editJson = htmlspecialchars(json_encode([
            'id'       => $id,
            'title'    => (string)($T['title'] ?? ''),
            'info'     => (string)($T['info'] ?? ''),
            'priority' => $prio,
            'due'      => $dueTs,
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        return '
<div class="tm-item' . ($done ? ' tm-done' : '') . '">
  <div class="tm-item-left">' . $chk . '<span class="tm-prio-dot tm-prio-' . $prio . '"></span></div>
  <div class="tm-item-body" onclick="tmOpenEdit(\'' . $editJson . '\')">
    <div class="tm-title">' . $title . '</div>' . $infoHtml
            . ($badges !== '' ? '<div class="tm-badges">' . $badges . '</div>' : '') . '
  </div>
  <button class="tm-item-del" onclick="event.stopPropagation();tmDelete(' . $id . ')">&#x2715;</button>
</div>';
    }

    private function BuildJs(int $Iid): string
    {
        // IPS_RequestAction wird direkt über den IPS WebFront JS-Kontext aufgerufen.
        // In IPS View / HTMLBox steht window.IPS zur Verfügung.
        return '<script>
(function() {
  var IID = ' . $Iid . ';

  // Universelle Aktion: versucht alle bekannten IPS-Wege
  function tmSend(ident, payload) {
    var value = JSON.stringify(payload);
    var st = document.getElementById("tm-status");

    // Weg 1: IPS WebFront nativer Aufruf
    if (typeof IPS !== "undefined" && IPS.requestAction) {
      try { IPS.requestAction(IID, ident, value); return; } catch(e) {}
    }

    // Weg 2: window.sendRequest (ältere IPS-Versionen)
    if (typeof sendRequest !== "undefined") {
      try { sendRequest("RequestAction", {instanceID: IID, ident: ident, value: value}); return; } catch(e) {}
    }

    // Weg 3: JSON-RPC über aktuellen Host+Port (funktioniert bei ipmagic)
    var urls = [
      location.origin + "/api/",
      location.protocol + "//" + location.hostname + ":3777/api/",
      location.protocol + "//" + location.hostname + ":82/api/"
    ];

    function tryUrl(idx) {
      if (idx >= urls.length) {
        if (st) st.textContent = "Verbindung fehlgeschlagen";
        return;
      }
      fetch(urls[idx], {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({jsonrpc: "2.0", method: "IPS_RequestAction", params: [IID, ident, value], id: 1})
      })
      .then(function(r) {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      })
      .then(function(d) {
        if (d && d.error && st) {
          st.textContent = "Fehler: " + JSON.stringify(d.error);
        }
      })
      .catch(function() { tryUrl(idx + 1); });
    }
    tryUrl(0);
  }

  window.tmToggle = function(id, done) {
    tmSend("ToggleDone", {id: id, done: done});
  };

  window.tmDelete = function(id) {
    tmSend("DeleteTask", {id: id});
  };

  window.tmOpenAdd = function() {
    document.getElementById("tm-edit-id").value = "";
    document.getElementById("tm-f-title").value = "";
    document.getElementById("tm-f-info").value = "";
    document.getElementById("tm-f-prio").value = "normal";
    document.getElementById("tm-f-due").value = "";
    document.getElementById("tm-del-btn").style.display = "none";
    document.getElementById("tm-modal-title").textContent = "Neue Aufgabe";
    document.getElementById("tm-status").textContent = "";
    document.getElementById("tm-modal").classList.add("open");
    document.getElementById("tm-backdrop").classList.add("open");
    setTimeout(function() { document.getElementById("tm-f-title").focus(); }, 80);
  };

  window.tmOpenEdit = function(s) {
    var d;
    try { d = JSON.parse(s); } catch(e) { return; }
    document.getElementById("tm-edit-id").value = d.id || "";
    document.getElementById("tm-f-title").value = d.title || "";
    document.getElementById("tm-f-info").value = d.info || "";
    document.getElementById("tm-f-prio").value = d.priority || "normal";
    if (d.due > 0) {
      var dt = new Date(d.due * 1000);
      var pad = function(n) { return String(n).padStart(2, "0"); };
      document.getElementById("tm-f-due").value =
        dt.getFullYear() + "-" + pad(dt.getMonth() + 1) + "-" + pad(dt.getDate()) +
        "T" + pad(dt.getHours()) + ":" + pad(dt.getMinutes());
    } else {
      document.getElementById("tm-f-due").value = "";
    }
    document.getElementById("tm-del-btn").style.display = "";
    document.getElementById("tm-modal-title").textContent = "Aufgabe bearbeiten";
    document.getElementById("tm-status").textContent = "";
    document.getElementById("tm-modal").classList.add("open");
    document.getElementById("tm-backdrop").classList.add("open");
    setTimeout(function() { document.getElementById("tm-f-title").focus(); }, 80);
  };

  window.tmCloseModal = function() {
    document.getElementById("tm-modal").classList.remove("open");
    document.getElementById("tm-backdrop").classList.remove("open");
  };

  window.tmSaveModal = function() {
    var title = document.getElementById("tm-f-title").value.trim();
    if (!title) {
      document.getElementById("tm-f-title").style.borderColor = "#ff5a5a";
      document.getElementById("tm-f-title").focus();
      return;
    }
    document.getElementById("tm-f-title").style.borderColor = "";
    var info   = document.getElementById("tm-f-info").value.trim();
    var prio   = document.getElementById("tm-f-prio").value;
    var dueRaw = document.getElementById("tm-f-due").value;
    var due    = dueRaw ? Math.floor(new Date(dueRaw).getTime() / 1000) : 0;
    var editId = document.getElementById("tm-edit-id").value;
    if (editId) {
      tmSend("UpdateTask", {id: parseInt(editId, 10), title: title, info: info, priority: prio, due: due});
    } else {
      tmSend("AddTask", {title: title, info: info, priority: prio, due: due});
    }
    tmCloseModal();
  };

  window.tmDeleteFromModal = function() {
    var editId = document.getElementById("tm-edit-id").value;
    if (editId) {
      tmSend("DeleteTask", {id: parseInt(editId, 10)});
      tmCloseModal();
    }
  };

  document.addEventListener("keydown", function(e) {
    var m = document.getElementById("tm-modal");
    if (!m || !m.classList.contains("open")) return;
    if (e.key === "Enter" && document.activeElement && document.activeElement.tagName !== "TEXTAREA") {
      e.preventDefault();
      tmSaveModal();
    }
    if (e.key === "Escape") { tmCloseModal(); }
  });
})();
</script>';
    }

    private function BuildCss(bool $Dark): string
    {
        if ($Dark) {
            $v = '--bg:#1e1f23;--card:#2a2b30;--text:#f0f0f0;--muted:rgba(240,240,240,.45);--border:rgba(255,255,255,.10);--accent:#00cdab;--red:#ff5a5a;--orange:#ffaa40;--shadow:rgba(0,0,0,.45);--modal:#23242a;--inp:#1a1b1f;--overlay:rgba(0,0,0,.65);';
        } else {
            $v = '--bg:#f4f5f7;--card:#fff;--text:#1a1a2e;--muted:rgba(26,26,46,.45);--border:rgba(0,0,0,.10);--accent:#00897b;--red:#d32f2f;--orange:#e65100;--shadow:rgba(0,0,0,.12);--modal:#fff;--inp:#f4f5f7;--overlay:rgba(0,0,0,.40);';
        }

        return '.tm-wrap{' . $v . 'display:flex;flex-direction:column;gap:8px;font-family:"Segoe UI",system-ui,sans-serif;font-size:14px;color:var(--text);}
.tm-stats{display:flex;gap:8px;margin-bottom:4px;}
.tm-stat{flex:1;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:10px 6px;text-align:center;}
.tm-stat-val{font-size:26px;font-weight:800;line-height:1;}
.tm-stat-lbl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-top:4px;}
.tm-stat-open .tm-stat-val{color:var(--accent);}
.tm-stat-overdue .tm-stat-val{color:var(--red);}
.tm-stat-today .tm-stat-val{color:var(--orange);}
.tm-add-bar{margin-bottom:4px;}
.tm-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:10px;border:1px solid var(--border);padding:9px 16px;font-size:13px;font-weight:600;background:var(--card);color:var(--text);cursor:pointer;white-space:nowrap;font-family:inherit;}
.tm-btn:disabled{opacity:.5;cursor:not-allowed;}
.tm-btn-primary{background:var(--accent);border-color:var(--accent);color:#fff;}
.tm-btn-danger{background:var(--red);border-color:var(--red);color:#fff;}
.tm-btn-full{width:100%;box-sizing:border-box;}
.tm-item{display:flex;align-items:flex-start;gap:10px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:0;}
.tm-item.tm-done{opacity:.5;}
.tm-item-left{display:flex;flex-direction:column;align-items:center;gap:6px;padding-top:2px;flex-shrink:0;}
.tm-chk{width:18px;height:18px;cursor:pointer;accent-color:var(--accent);}
.tm-prio-dot{width:8px;height:8px;border-radius:50%;}
.tm-prio-dot.tm-prio-high{background:var(--red);}
.tm-prio-dot.tm-prio-normal{background:var(--accent);}
.tm-prio-dot.tm-prio-low{background:var(--muted);}
.tm-item-body{flex:1;min-width:0;cursor:pointer;}
.tm-title{font-weight:700;line-height:1.3;word-break:break-word;}
.tm-done .tm-title{text-decoration:line-through;}
.tm-info{font-size:12px;color:var(--muted);margin-top:3px;line-height:1.4;}
.tm-badges{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;}
.tm-badge{font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid var(--border);color:var(--muted);}
.tm-badge.tm-badge-high{border-color:var(--red);color:var(--red);background:rgba(255,90,90,.15);}
.tm-badge.tm-badge-normal{border-color:var(--accent);color:var(--accent);background:rgba(0,205,171,.15);}
.tm-badge.tm-badge-low{border-color:var(--muted);color:var(--muted);}
.tm-due-badge.tm-due-overdue{border-color:var(--red);color:var(--red);background:rgba(255,90,90,.15);}
.tm-due-badge.tm-due-today{border-color:var(--orange);color:var(--orange);background:rgba(255,170,64,.15);}
.tm-item-del{flex-shrink:0;background:transparent;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:2px 4px;border-radius:6px;}
.tm-item-del:hover{color:var(--red);}
.tm-section-header{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);padding:8px 4px 2px;}
.tm-empty{text-align:center;color:var(--muted);padding:24px 16px;font-size:13px;}
.tm-backdrop{display:none;position:fixed;inset:0;background:var(--overlay);z-index:9000;}
.tm-backdrop.open{display:block;}
.tm-modal{display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(420px,calc(100vw - 32px));max-height:calc(100vh - 48px);background:var(--modal);border:1px solid var(--border);border-radius:16px;box-shadow:0 8px 40px var(--shadow);z-index:9100;flex-direction:column;overflow:hidden;}
.tm-modal.open{display:flex;}
.tm-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1px solid var(--border);font-weight:700;font-size:15px;}
.tm-modal-close{background:transparent;border:none;color:var(--muted);cursor:pointer;font-size:16px;padding:0 2px;}
.tm-modal-body{padding:16px 18px;display:flex;flex-direction:column;gap:12px;overflow-y:auto;}
.tm-modal-footer{display:flex;gap:8px;padding:12px 18px 16px;border-top:1px solid var(--border);justify-content:flex-end;}
.tm-field{display:flex;flex-direction:column;gap:5px;}
.tm-field-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.tm-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);}
.tm-input{background:var(--inp);border:1px solid var(--border);border-radius:8px;padding:9px 11px;font-size:13px;color:var(--text);outline:none;width:100%;box-sizing:border-box;font-family:inherit;}
.tm-input:focus{border-color:var(--accent);}
.tm-textarea{resize:vertical;min-height:64px;line-height:1.4;}';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function LoadTasks(): array
    {
        $data = json_decode($this->ReadAttributeString('Tasks'), true);
        return is_array($data) ? $data : [];
    }

    private function SaveTasks(array $Tasks)
    {
        $this->WriteAttributeString('Tasks', json_encode(array_values($Tasks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function ValidatePriority(string $P): string
    {
        return in_array($P, ['low', 'normal', 'high'], true) ? $P : 'normal';
    }

    private function SortScore(array $T): int
    {
        $map = ['high' => 0, 'normal' => 1000, 'low' => 2000];
        $s   = isset($map[$T['priority']]) ? $map[$T['priority']] : 1000;
        $due = (int)($T['due'] ?? 0);
        return $due > 0 ? $s + ($due % 100000) : $s + 99999;
    }
}
