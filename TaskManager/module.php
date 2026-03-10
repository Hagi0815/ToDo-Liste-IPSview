<?php

declare(strict_types=1);

class TaskManager extends IPSModuleStrict
{
    // ─────────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ─────────────────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        // Daten
        $this->RegisterAttributeString('Tasks', '[]');
        $this->RegisterAttributeInteger('NextID', 1);

        // Einstellungen
        $this->RegisterPropertyInteger('MaxCompletedVisible', 10);
        $this->RegisterPropertyBoolean('ShowStats', true);
        $this->RegisterPropertyBoolean('ShowPriority', true);
        $this->RegisterPropertyBoolean('ShowDueDate', true);
        $this->RegisterPropertyBoolean('DarkMode', true);

        // Variable für IPS View HTMLBox
        $this->RegisterVariableString('TaskListHtml', 'Aufgabenliste', '~HTMLBox', 1);
        $this->RegisterVariableInteger('OpenTasks', 'Offene Aufgaben', '', 2);
        $this->RegisterVariableInteger('OverdueTasks', 'Überfällig', '', 3);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->Refresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Actions – werden via IPS_RequestAction aus der HTMLBox aufgerufen
    // ─────────────────────────────────────────────────────────────────────────

    public function RequestAction(string $Ident, mixed $Value): void
    {
        IPS_LogMessage('TaskManager', 'RequestAction aufgerufen: Ident=' . $Ident . ' Value=' . print_r($Value, true));
        switch ($Ident) {
            case 'AddTask':
                $data = $this->Decode($Value);
                IPS_LogMessage('TaskManager', 'AddTask Decoded: ' . print_r($data, true));
                $this->AddTask($data);
                break;
            case 'UpdateTask':
                $this->UpdateTask($this->Decode($Value));
                break;
            case 'ToggleDone':
                $this->ToggleDone($this->Decode($Value));
                break;
            case 'DeleteTask':
                $this->DeleteTask($this->Decode($Value));
                break;
            case 'DeleteAllCompleted':
                $this->DeleteAllCompleted();
                break;
            default:
                throw new RuntimeException('Unbekannte Aktion: ' . $Ident);
        }
        $this->Refresh();
        IPS_LogMessage('TaskManager', 'Refresh abgeschlossen nach: ' . $Ident);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Öffentliche Methoden (aufrufbar per Skript)
    // ─────────────────────────────────────────────────────────────────────────

    public function TM_AddTask(string $Title, string $Info = '', string $Priority = 'normal', int $Due = 0): int
    {
        $data = ['title' => $Title, 'info' => $Info, 'priority' => $Priority, 'due' => $Due];
        $id = $this->AddTask($data);
        $this->Refresh();
        return $id;
    }

    public function TM_DeleteAllCompleted(): void
    {
        $this->DeleteAllCompleted();
        $this->Refresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Task CRUD
    // ─────────────────────────────────────────────────────────────────────────

    private function AddTask(array $Data): int
    {
        $tasks = $this->LoadTasks();
        $id = $this->ReadAttributeInteger('NextID');
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

    private function UpdateTask(array $Data): void
    {
        $id = (int)($Data['id'] ?? 0);
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

    private function ToggleDone(array $Data): void
    {
        $id   = (int)($Data['id'] ?? 0);
        $done = (bool)($Data['done'] ?? false);
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

    private function DeleteTask(array $Data): void
    {
        $id = (int)($Data['id'] ?? 0);
        $tasks = array_values(array_filter($this->LoadTasks(), fn($t) => (int)$t['id'] !== $id));
        $this->SaveTasks($tasks);
    }

    private function DeleteAllCompleted(): void
    {
        $tasks = array_values(array_filter($this->LoadTasks(), fn($t) => empty($t['done'])));
        $this->SaveTasks($tasks);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Render
    // ─────────────────────────────────────────────────────────────────────────

    private function Refresh(): void
    {
        $tasks    = $this->LoadTasks();
        $open     = count(array_filter($tasks, fn($t) => empty($t['done'])));
        $now      = time();
        $todayEnd = mktime(23, 59, 59);
        $overdue  = count(array_filter($tasks, fn($t) => empty($t['done']) && (int)($t['due'] ?? 0) > 0 && (int)$t['due'] < $now));

        $this->SetValue('OpenTasks', $open);
        $this->SetValue('OverdueTasks', $overdue);
        $this->SetValue('TaskListHtml', $this->BuildHtml($tasks));
    }

    private function BuildHtml(array $Tasks): string
    {
        $iid         = $this->InstanceID;
        $dark        = $this->ReadPropertyBoolean('DarkMode');
        $showStats   = $this->ReadPropertyBoolean('ShowStats');
        $showPrio    = $this->ReadPropertyBoolean('ShowPriority');
        $showDue     = $this->ReadPropertyBoolean('ShowDueDate');
        $maxDone     = $this->ReadPropertyInteger('MaxCompletedVisible');

        $open   = array_values(array_filter($Tasks, fn($t) => empty($t['done'])));
        $done   = array_values(array_filter($Tasks, fn($t) => !empty($t['done'])));

        // Nach Priorität + Fälligkeit sortieren
        usort($open, fn($a, $b) => $this->SortScore($a) <=> $this->SortScore($b));
        usort($done, fn($a, $b) => (int)($b['completedAt'] ?? 0) <=> (int)($a['completedAt'] ?? 0));

        if ($maxDone > 0) {
            $done = array_slice($done, 0, $maxDone);
        }

        $now      = time();
        $todayS   = mktime(0, 0, 0);
        $todayE   = mktime(23, 59, 59);

        $totalOpen   = count(array_filter($Tasks, fn($t) => empty($t['done'])));
        $totalOverdue = count(array_filter($Tasks, fn($t) => empty($t['done']) && (int)($t['due'] ?? 0) > 0 && (int)$t['due'] < $now));
        $totalToday  = count(array_filter($Tasks, fn($t) => empty($t['done']) && (int)($t['due'] ?? 0) >= $todayS && (int)($t['due'] ?? 0) <= $todayE));

        // ── CSS ──────────────────────────────────────────────────────────────
        $css = $this->BuildCss($dark);

        // ── JavaScript ───────────────────────────────────────────────────────
        $js = $this->BuildJs($iid);

        // ── HTML Body ────────────────────────────────────────────────────────
        $body = '';

        // Statistik
        if ($showStats) {
            $body .= '<div class="tm-stats">';
            $body .= '<div class="tm-stat tm-stat-open"><div class="tm-stat-val">' . $totalOpen . '</div><div class="tm-stat-lbl">Offen</div></div>';
            $body .= '<div class="tm-stat tm-stat-overdue"><div class="tm-stat-val">' . $totalOverdue . '</div><div class="tm-stat-lbl">Überfällig</div></div>';
            $body .= '<div class="tm-stat tm-stat-today"><div class="tm-stat-val">' . $totalToday . '</div><div class="tm-stat-lbl">Heute</div></div>';
            $body .= '</div>';
        }

        // Neue Aufgabe Formular
        $body .= $this->BuildAddFormHtml();

        // Offene Aufgaben
        foreach ($open as $t) {
            $body .= $this->BuildTaskRow($t, $showPrio, $showDue, $now, $todayS, $todayE);
        }

        // Erledigte Aufgaben
        if (!empty($done)) {
            $body .= '<div class="tm-section-header">Erledigt</div>';
            foreach ($done as $t) {
                $body .= $this->BuildTaskRow($t, $showPrio, $showDue, $now, $todayS, $todayE);
            }
        }

        if (empty($open) && empty($done)) {
            $body .= '<div class="tm-empty">Keine Aufgaben vorhanden. Leg eine neue an!</div>';
        }

        return '<style>' . $css . '</style>' . $js . '<div class="tm-wrap">' . $body . '</div>';
    }

    private function BuildAddFormHtml(): string
    {
        return '
<div class="tm-add-bar" id="tm-add-bar">
  <button class="tm-btn tm-btn-primary tm-btn-full" onclick="tmOpenAdd()">＋ Neue Aufgabe</button>
</div>
<div class="tm-modal-backdrop" id="tm-modal-backdrop" onclick="tmCloseModal()"></div>
<div class="tm-modal" id="tm-modal">
  <div class="tm-modal-header">
    <span id="tm-modal-title">Neue Aufgabe</span>
    <button class="tm-modal-close" onclick="tmCloseModal()">✕</button>
  </div>
  <div class="tm-modal-body">
    <input type="hidden" id="tm-edit-id" value="" />
    <div class="tm-field">
      <label class="tm-label">Titel *</label>
      <input class="tm-input" id="tm-f-title" type="text" placeholder="Aufgabe..." />
    </div>
    <div class="tm-field">
      <label class="tm-label">Beschreibung</label>
      <textarea class="tm-input tm-textarea" id="tm-f-info" placeholder="Notiz..."></textarea>
    </div>
    <div class="tm-field-row">
      <div class="tm-field">
        <label class="tm-label">Priorität</label>
        <select class="tm-input tm-select" id="tm-f-prio">
          <option value="low">Niedrig</option>
          <option value="normal" selected>Normal</option>
          <option value="high">Hoch</option>
        </select>
      </div>
      <div class="tm-field">
        <label class="tm-label">Fällig am</label>
        <input class="tm-input" id="tm-f-due" type="datetime-local" />
      </div>
    </div>
  </div>
  <div class="tm-modal-footer">
    <button class="tm-btn tm-btn-danger" id="tm-del-btn" onclick="tmDeleteFromModal()" style="display:none">🗑 Löschen</button>
    <button class="tm-btn" onclick="tmCloseModal()">Abbrechen</button>
    <button class="tm-btn tm-btn-primary" onclick="tmSaveModal()">Speichern</button>
  </div>
</div>';
    }

    private function BuildTaskRow(array $T, bool $ShowPrio, bool $ShowDue, int $Now, int $TodayS, int $TodayE): string
    {
        $id      = (int)$T['id'];
        $title   = htmlspecialchars((string)($T['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $info    = htmlspecialchars((string)($T['info'] ?? ''), ENT_QUOTES, 'UTF-8');
        $done    = !empty($T['done']);
        $prio    = (string)($T['priority'] ?? 'normal');
        $dueTs   = (int)($T['due'] ?? 0);

        $rowClass = 'tm-item' . ($done ? ' tm-done' : '');

        // Prioritäts-Indikator
        $prioBar = '<span class="tm-prio-dot tm-prio-' . $prio . '"></span>';

        // Checkbox
        $chk = '<input type="checkbox" class="tm-chk" data-id="' . $id . '" '
             . ($done ? 'checked' : '') . ' onchange="tmToggle(' . $id . ', this.checked)" />';

        // Info
        $infoHtml = $info !== '' ? '<div class="tm-info">' . $info . '</div>' : '';

        // Badges
        $badges = '';
        if ($ShowPrio) {
            $prioLabels = ['low' => 'Niedrig', 'normal' => 'Normal', 'high' => 'Hoch'];
            $prioLabel  = $prioLabels[$prio] ?? 'Normal';
            $badges .= '<span class="tm-badge tm-badge-' . $prio . '">' . $prioLabel . '</span>';
        }
        if ($ShowDue && $dueTs > 0) {
            $dueClass = '';
            if ($dueTs < $Now) {
                $dueClass = ' tm-due-overdue';
            } elseif ($dueTs >= $TodayS && $dueTs <= $TodayE) {
                $dueClass = ' tm-due-today';
            }
            $dueText = date('d.m.Y H:i', $dueTs);
            $badges .= '<span class="tm-badge tm-due-badge' . $dueClass . '">📅 ' . $dueText . '</span>';
        }

        // Edit-Daten als JSON für JS
        $editData = json_encode([
            'id'       => $id,
            'title'    => (string)($T['title'] ?? ''),
            'info'     => (string)($T['info'] ?? ''),
            'priority' => $prio,
            'due'      => $dueTs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $editDataEsc = htmlspecialchars($editData, ENT_QUOTES, 'UTF-8');

        return '
<div class="' . $rowClass . '" data-id="' . $id . '">
  <div class="tm-item-left">
    ' . $chk . '
    ' . $prioBar . '
  </div>
  <div class="tm-item-body" onclick="tmOpenEdit(' . htmlspecialchars($editData, ENT_QUOTES, 'UTF-8') . ')">
    <div class="tm-title">' . $title . '</div>
    ' . $infoHtml . '
    ' . ($badges !== '' ? '<div class="tm-badges">' . $badges . '</div>' : '') . '
  </div>
  <button class="tm-item-del" onclick="event.stopPropagation();tmDelete(' . $id . ')" title="Löschen">✕</button>
</div>';
    }

    private function BuildJs(int $Iid): string
    {
        return '<script>
(function() {
  var IID = ' . $Iid . ';

  function ipsAction(ident, value) {
    var payload = JSON.stringify(value);
    fetch("/api/", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({
        jsonrpc: "2.0",
        method: "IPS_RequestAction",
        params: [IID, ident, payload],
        id: Date.now()
      })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data && data.error) {
        alert("Fehler: " + JSON.stringify(data.error));
      }
    })
    .catch(function(e) {
      alert("Verbindungsfehler: " + e);
    });
  }

  window.tmToggle = function(id, done) {
    ipsAction("ToggleDone", {id: id, done: done});
  };

  window.tmDelete = function(id) {
    ipsAction("DeleteTask", {id: id});
  };

  window.tmOpenAdd = function() {
    document.getElementById("tm-edit-id").value = "";
    document.getElementById("tm-f-title").value = "";
    document.getElementById("tm-f-info").value = "";
    document.getElementById("tm-f-prio").value = "normal";
    document.getElementById("tm-f-due").value = "";
    document.getElementById("tm-del-btn").style.display = "none";
    document.getElementById("tm-modal-title").textContent = "Neue Aufgabe";
    document.getElementById("tm-modal").classList.add("open");
    document.getElementById("tm-modal-backdrop").classList.add("open");
    setTimeout(function(){ document.getElementById("tm-f-title").focus(); }, 80);
  };

  window.tmOpenEdit = function(data) {
    var d = typeof data === "string" ? JSON.parse(data) : data;
    document.getElementById("tm-edit-id").value = d.id;
    document.getElementById("tm-f-title").value = d.title || "";
    document.getElementById("tm-f-info").value = d.info || "";
    document.getElementById("tm-f-prio").value = d.priority || "normal";
    if (d.due && d.due > 0) {
      var dt = new Date(d.due * 1000);
      var pad = function(n){ return String(n).padStart(2, "0"); };
      document.getElementById("tm-f-due").value =
        dt.getFullYear() + "-" + pad(dt.getMonth()+1) + "-" + pad(dt.getDate()) +
        "T" + pad(dt.getHours()) + ":" + pad(dt.getMinutes());
    } else {
      document.getElementById("tm-f-due").value = "";
    }
    document.getElementById("tm-del-btn").style.display = "";
    document.getElementById("tm-modal-title").textContent = "Aufgabe bearbeiten";
    document.getElementById("tm-modal").classList.add("open");
    document.getElementById("tm-modal-backdrop").classList.add("open");
    setTimeout(function(){ document.getElementById("tm-f-title").focus(); }, 80);
  };

  window.tmCloseModal = function() {
    document.getElementById("tm-modal").classList.remove("open");
    document.getElementById("tm-modal-backdrop").classList.remove("open");
  };

  window.tmSaveModal = function() {
    var title = document.getElementById("tm-f-title").value.trim();
    if (!title) {
      document.getElementById("tm-f-title").style.borderColor = "red";
      document.getElementById("tm-f-title").focus();
      return;
    }
    document.getElementById("tm-f-title").style.borderColor = "";
    var info   = document.getElementById("tm-f-info").value.trim();
    var prio   = document.getElementById("tm-f-prio").value;
    var dueRaw = document.getElementById("tm-f-due").value;
    var due    = 0;
    if (dueRaw) {
      due = Math.floor(new Date(dueRaw).getTime() / 1000);
    }
    var editId = document.getElementById("tm-edit-id").value;
    if (editId) {
      ipsAction("UpdateTask", {id: parseInt(editId, 10), title: title, info: info, priority: prio, due: due});
    } else {
      ipsAction("AddTask", {title: title, info: info, priority: prio, due: due});
    }
    tmCloseModal();
  };

  window.tmDeleteFromModal = function() {
    var editId = document.getElementById("tm-edit-id").value;
    if (!editId) return;
    ipsAction("DeleteTask", {id: parseInt(editId, 10)});
    tmCloseModal();
  };

  document.addEventListener("keydown", function(e) {
    var modal = document.getElementById("tm-modal");
    if (!modal || !modal.classList.contains("open")) return;
    if (e.key === "Enter") {
      var active = document.activeElement;
      if (active && active.tagName !== "TEXTAREA") {
        e.preventDefault();
        tmSaveModal();
      }
    }
    if (e.key === "Escape") {
      tmCloseModal();
    }
  });

})();
</script>';
    }


    private function BuildCss(bool $Dark): string
    {
        if ($Dark) {
            $vars = '
  --tm-bg:        #1e1f23;
  --tm-card:      #2a2b30;
  --tm-card2:     #32333a;
  --tm-text:      #f0f0f0;
  --tm-muted:     rgba(240,240,240,0.45);
  --tm-border:    rgba(255,255,255,0.10);
  --tm-accent:    #00cdab;
  --tm-accent2:   #0097a7;
  --tm-red:       #ff5a5a;
  --tm-orange:    #ffaa40;
  --tm-shadow:    rgba(0,0,0,0.45);
  --tm-modal-bg:  #23242a;
  --tm-input-bg:  #1a1b1f;
  --tm-overlay:   rgba(0,0,0,0.65);';
        } else {
            $vars = '
  --tm-bg:        #f4f5f7;
  --tm-card:      #ffffff;
  --tm-card2:     #f0f1f3;
  --tm-text:      #1a1a2e;
  --tm-muted:     rgba(26,26,46,0.45);
  --tm-border:    rgba(0,0,0,0.10);
  --tm-accent:    #00897b;
  --tm-accent2:   #00695c;
  --tm-red:       #d32f2f;
  --tm-orange:    #e65100;
  --tm-shadow:    rgba(0,0,0,0.12);
  --tm-modal-bg:  #ffffff;
  --tm-input-bg:  #f4f5f7;
  --tm-overlay:   rgba(0,0,0,0.40);';
        }

        return '.tm-wrap {' . $vars . '
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
  font-size: 14px;
  color: var(--tm-text);
  background: transparent;
  position: relative;
}

/* Stats */
.tm-stats {
  display: flex;
  gap: 8px;
  margin-bottom: 4px;
}
.tm-stat {
  flex: 1;
  background: var(--tm-card);
  border: 1px solid var(--tm-border);
  border-radius: 12px;
  padding: 10px 6px;
  text-align: center;
  box-shadow: 0 1px 4px var(--tm-shadow);
}
.tm-stat-val {
  font-size: 26px;
  font-weight: 800;
  line-height: 1;
}
.tm-stat-lbl {
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .6px;
  color: var(--tm-muted);
  margin-top: 4px;
}
.tm-stat-open .tm-stat-val   { color: var(--tm-accent); }
.tm-stat-overdue .tm-stat-val { color: var(--tm-red); }
.tm-stat-today .tm-stat-val  { color: var(--tm-orange); }

/* Add bar */
.tm-add-bar { margin-bottom: 4px; }

/* Buttons */
.tm-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border-radius: 10px;
  border: 1px solid var(--tm-border);
  padding: 9px 16px;
  font-size: 13px;
  font-weight: 600;
  background: var(--tm-card);
  color: var(--tm-text);
  cursor: pointer;
  transition: opacity .15s, transform .1s;
  white-space: nowrap;
}
.tm-btn:active { transform: scale(.97); }
.tm-btn-primary {
  background: var(--tm-accent);
  border-color: var(--tm-accent);
  color: #fff;
}
.tm-btn-danger {
  background: var(--tm-red);
  border-color: var(--tm-red);
  color: #fff;
}
.tm-btn-full { width: 100%; box-sizing: border-box; }

/* Task Item */
.tm-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  background: var(--tm-card);
  border: 1px solid var(--tm-border);
  border-radius: 12px;
  padding: 12px;
  box-shadow: 0 1px 3px var(--tm-shadow);
  transition: opacity .2s;
  position: relative;
}
.tm-item.tm-done {
  opacity: .5;
}
.tm-item-left {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding-top: 2px;
  flex-shrink: 0;
}
.tm-chk {
  width: 18px;
  height: 18px;
  cursor: pointer;
  accent-color: var(--tm-accent);
  flex-shrink: 0;
}
.tm-prio-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.tm-prio-dot.tm-prio-high   { background: var(--tm-red); }
.tm-prio-dot.tm-prio-normal { background: var(--tm-accent); }
.tm-prio-dot.tm-prio-low    { background: var(--tm-muted); }

.tm-item-body {
  flex: 1;
  min-width: 0;
  cursor: pointer;
}
.tm-title {
  font-weight: 700;
  line-height: 1.3;
  word-break: break-word;
}
.tm-done .tm-title { text-decoration: line-through; }
.tm-info {
  font-size: 12px;
  color: var(--tm-muted);
  margin-top: 3px;
  line-height: 1.4;
  word-break: break-word;
}
.tm-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-top: 6px;
}
.tm-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 999px;
  border: 1px solid var(--tm-border);
  color: var(--tm-muted);
  white-space: nowrap;
}
.tm-badge.tm-badge-high   { border-color: var(--tm-red);    color: var(--tm-red);    background: rgba(255,90,90,.15); }
.tm-badge.tm-badge-normal { border-color: var(--tm-accent); color: var(--tm-accent); background: rgba(0,205,171,.15); }
.tm-badge.tm-badge-low    { border-color: var(--tm-muted);  color: var(--tm-muted); }
.tm-due-badge             { border-color: var(--tm-border); }
.tm-due-badge.tm-due-overdue { border-color: var(--tm-red);    color: var(--tm-red);    background: rgba(255,90,90,.15); }
.tm-due-badge.tm-due-today   { border-color: var(--tm-orange); color: var(--tm-orange); background: rgba(255,170,64,.15); }

.tm-item-del {
  flex-shrink: 0;
  background: transparent;
  border: none;
  color: var(--tm-muted);
  cursor: pointer;
  font-size: 14px;
  padding: 2px 4px;
  border-radius: 6px;
  transition: color .15s, background .15s;
  line-height: 1;
}
.tm-item-del:hover { color: var(--tm-red); background: rgba(255,90,90,.12); }

/* Section header */
.tm-section-header {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .8px;
  color: var(--tm-muted);
  padding: 8px 4px 2px;
}

/* Empty state */
.tm-empty {
  text-align: center;
  color: var(--tm-muted);
  padding: 24px 16px;
  font-size: 13px;
}

/* Modal Backdrop */
.tm-modal-backdrop {
  display: none;
  position: fixed;
  inset: 0;
  background: var(--tm-overlay);
  z-index: 9000;
}
.tm-modal-backdrop.open { display: block; }

/* Modal */
.tm-modal {
  display: none;
  position: fixed;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  width: min(420px, calc(100vw - 32px));
  max-height: calc(100vh - 48px);
  background: var(--tm-modal-bg);
  border: 1px solid var(--tm-border);
  border-radius: 16px;
  box-shadow: 0 8px 40px var(--tm-shadow);
  z-index: 9100;
  flex-direction: column;
  overflow: hidden;
}
.tm-modal.open { display: flex; }
.tm-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 18px 12px;
  border-bottom: 1px solid var(--tm-border);
  font-weight: 700;
  font-size: 15px;
}
.tm-modal-close {
  background: transparent;
  border: none;
  color: var(--tm-muted);
  cursor: pointer;
  font-size: 16px;
  padding: 0 2px;
  line-height: 1;
}
.tm-modal-body {
  padding: 16px 18px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  overflow-y: auto;
}
.tm-modal-footer {
  display: flex;
  gap: 8px;
  padding: 12px 18px 16px;
  border-top: 1px solid var(--tm-border);
  justify-content: flex-end;
}
.tm-field { display: flex; flex-direction: column; gap: 5px; }
.tm-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.tm-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .5px;
  color: var(--tm-muted);
}
.tm-input {
  background: var(--tm-input-bg);
  border: 1px solid var(--tm-border);
  border-radius: 8px;
  padding: 9px 11px;
  font-size: 13px;
  color: var(--tm-text);
  outline: none;
  width: 100%;
  box-sizing: border-box;
  transition: border-color .15s;
  font-family: inherit;
}
.tm-input:focus { border-color: var(--tm-accent); }
.tm-textarea {
  resize: vertical;
  min-height: 64px;
  line-height: 1.4;
}
.tm-select { appearance: none; cursor: pointer; }
';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function LoadTasks(): array
    {
        $raw = $this->ReadAttributeString('Tasks');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function SaveTasks(array $Tasks): void
    {
        $this->WriteAttributeString('Tasks', json_encode(array_values($Tasks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function Decode(mixed $Value): array
    {
        if (is_string($Value)) {
            $decoded = json_decode($Value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($Value) ? $Value : [];
    }

    private function ValidatePriority(string $P): string
    {
        return in_array($P, ['low', 'normal', 'high'], true) ? $P : 'normal';
    }

    private function SortScore(array $T): int
    {
        $prioScore = ['high' => 0, 'normal' => 1000, 'low' => 2000];
        $p = $prioScore[(string)($T['priority'] ?? 'normal')] ?? 1000;
        $due = (int)($T['due'] ?? 0);
        // Aufgaben mit Fälligkeit kommen nach oben, sortiert nach Datum
        if ($due > 0) {
            return $p + ($due % 100000);
        }
        return $p + 99999;
    }
}
