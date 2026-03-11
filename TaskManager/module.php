<?php

class TaskManager extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeString('Tasks', '[]');
        $this->RegisterAttributeInteger('NextID', 1);
        $this->RegisterPropertyBoolean('DarkMode', true);
        $this->RegisterPropertyBoolean('ShowStats', true);
        $this->RegisterPropertyInteger('MaxCompletedVisible', 10);
        $this->RegisterVariableString('TasksJson', 'Aufgaben JSON', '', 1);
        $this->RegisterVariableString('HtmlBox', 'Aufgabenliste', '~HTMLBox', 2);
        $this->RegisterVariableInteger('OpenTasks', 'Offene Aufgaben', '', 3);
        $this->RegisterVariableInteger('OverdueTasks', 'Ueberfaellig', '', 4);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook();
        $this->Refresh();
    }

    public function ProcessHookData()
    {
        // GET: vollstaendige HTML-Seite ausliefern (fuer IPS View HTMLBox als src=URL)
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Content-Type: text/html; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            echo $this->BuildHtml($this->LoadTasks());
            return;
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            echo '';
            return;
        }
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['action'])) {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'bad request'));
            return;
        }
        $action = (string)$data['action'];
        $payload = (isset($data['payload']) && is_array($data['payload'])) ? $data['payload'] : array();
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
                echo json_encode(array('ok' => false, 'error' => 'unknown action'));
                return;
        }
        $this->Refresh();
        echo json_encode(array('ok' => true));
    }

    public function TM_AddTask($Title, $Info = '', $Priority = 'normal', $Due = 0)
    {
        $id = $this->AddTask(array('title' => $Title, 'info' => $Info, 'priority' => $Priority, 'due' => $Due));
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

    private function AddTask($Data)
    {
        $tasks = $this->LoadTasks();
        $id = $this->ReadAttributeInteger('NextID');
        $this->WriteAttributeInteger('NextID', $id + 1);
        $tasks[] = array(
            'id'        => $id,
            'title'     => trim((string)isset($Data['title']) ? $Data['title'] : ''),
            'info'      => trim((string)isset($Data['info']) ? $Data['info'] : ''),
            'priority'  => $this->ValidPrio(isset($Data['priority']) ? (string)$Data['priority'] : 'normal'),
            'due'       => (int)(isset($Data['due']) ? $Data['due'] : 0),
            'done'      => false,
            'createdAt' => time()
        );
        $this->SaveTasks($tasks);
        return $id;
    }

    private function UpdateTask($Data)
    {
        $id = (int)(isset($Data['id']) ? $Data['id'] : -1);
        $tasks = $this->LoadTasks();
        for ($i = 0; $i < count($tasks); $i++) {
            if ((int)$tasks[$i]['id'] === $id) {
                $tasks[$i]['title']    = trim((string)(isset($Data['title'])    ? $Data['title']    : $tasks[$i]['title']));
                $tasks[$i]['info']     = trim((string)(isset($Data['info'])     ? $Data['info']     : $tasks[$i]['info']));
                $tasks[$i]['priority'] = $this->ValidPrio((string)(isset($Data['priority']) ? $Data['priority'] : $tasks[$i]['priority']));
                $tasks[$i]['due']      = (int)(isset($Data['due'])      ? $Data['due']      : $tasks[$i]['due']);
                break;
            }
        }
        $this->SaveTasks($tasks);
    }

    private function ToggleDone($Data)
    {
        $id   = (int)(isset($Data['id'])   ? $Data['id']   : -1);
        $done = (bool)(isset($Data['done']) ? $Data['done'] : false);
        $tasks = $this->LoadTasks();
        for ($i = 0; $i < count($tasks); $i++) {
            if ((int)$tasks[$i]['id'] === $id) {
                $tasks[$i]['done'] = $done;
                if ($done) {
                    $tasks[$i]['completedAt'] = time();
                } else {
                    unset($tasks[$i]['completedAt']);
                }
                break;
            }
        }
        $this->SaveTasks($tasks);
    }

    private function DeleteTask($Data)
    {
        $id = (int)(isset($Data['id']) ? $Data['id'] : -1);
        $result = array();
        foreach ($this->LoadTasks() as $t) {
            if ((int)$t['id'] !== $id) {
                $result[] = $t;
            }
        }
        $this->SaveTasks($result);
    }

    private function DeleteAllCompleted()
    {
        $result = array();
        foreach ($this->LoadTasks() as $t) {
            if (empty($t['done'])) {
                $result[] = $t;
            }
        }
        $this->SaveTasks($result);
    }


    private function GetHookUrl()
    {
        $hook = '/hook/taskmanager_' . $this->InstanceID;
        try {
            $connectIds = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930D26F0B3}');
            if (!empty($connectIds)) {
                $connectUrl = CC_GetURL($connectIds[0]);
                if (!empty($connectUrl)) {
                    return rtrim($connectUrl, '/') . $hook;
                }
            }
        } catch (Exception $e) {}
        return $hook;
    }

    private function Refresh()
    {
        $tasks = $this->LoadTasks();
        $now = time();
        $open = 0;
        $overdue = 0;
        foreach ($tasks as $t) {
            if (!empty($t['done'])) continue;
            $open++;
            $due = (int)(isset($t['due']) ? $t['due'] : 0);
            if ($due > 0 && $due < $now) $overdue++;
        }
        $this->SetValue('TasksJson', json_encode($tasks));
        $this->SetValue('OpenTasks', $open);
        $this->SetValue('OverdueTasks', $overdue);
        $hookUrl = $this->GetHookUrl();
        $iframe = '<iframe src="' . $hookUrl . '" style="width:100%;height:100%;border:none;min-height:600px;" frameborder="0"></iframe>';
        $this->SetValue('HtmlBox', $iframe);
    }

    private function BuildHtml($tasks)
    {
        $iid     = $this->InstanceID;
        $dark    = (bool)$this->ReadPropertyBoolean('DarkMode');
        $fullHook = $this->GetHookUrl();
        $now     = time();
        $todayE  = mktime(23, 59, 59);

        $open = array();
        $done = array();
        foreach ($tasks as $t) {
            if (!empty($t['done'])) $done[] = $t;
            else $open[] = $t;
        }

        $maxDone = (int)$this->ReadPropertyInteger('MaxCompletedVisible');
        if ($maxDone > 0) $done = array_slice($done, 0, $maxDone);

        $rows = '';
        foreach ($open as $t) $rows .= $this->BuildRow($t, $now, $todayE);
        if (!empty($done)) {
            $rows .= '<div class="divider">Erledigt</div>';
            foreach ($done as $t) $rows .= $this->BuildRow($t, $now, $todayE);
        }
        if (empty($open) && empty($done)) {
            $rows = '<div class="empty">Keine Aufgaben vorhanden</div>';
        }

        $stats = '';
        if ((bool)$this->ReadPropertyBoolean('ShowStats')) {
            $ov = 0;
            $td = 0;
            $todayS = mktime(0, 0, 0);
            foreach ($open as $t) {
                $due = (int)(isset($t['due']) ? $t['due'] : 0);
                if ($due > 0 && $due < $now) $ov++;
                if ($due >= $todayS && $due <= $todayE) $td++;
            }
            $stats = '<div class="stats">'
                . '<div class="stat s-open"><b>' . count($open) . '</b><span>Offen</span></div>'
                . '<div class="stat s-ov"><b>' . $ov . '</b><span>&#220;berf&#228;llig</span></div>'
                . '<div class="stat s-td"><b>' . $td . '</b><span>Heute</span></div>'
                . '</div>';
        }

        $bg    = $dark ? '#18191d' : '#f0f2f5';
        $card  = $dark ? '#24252b' : '#ffffff';
        $text  = $dark ? '#f0f0f0' : '#1a1a2e';
        $muted = $dark ? 'rgba(240,240,240,.4)' : 'rgba(26,26,46,.4)';
        $bord  = $dark ? 'rgba(255,255,255,.09)' : 'rgba(0,0,0,.09)';
        $acc   = $dark ? '#00cdab' : '#00897b';
        $red   = $dark ? '#e00000' : '#e00000';
        $ora   = $dark ? '#ffaa40' : '#e65100';
        $inp   = $dark ? '#14151a' : '#f0f2f5';
        $ov2   = $dark ? 'rgba(0,0,0,.7)' : 'rgba(0,0,0,.4)';
        $sh    = $dark ? 'rgba(0,0,0,.5)' : 'rgba(0,0,0,.1)';
        $modal = $dark ? '#1e1f24' : '#ffffff';

        $css = '*{box-sizing:border-box;margin:0;padding:0}'
            . 'body{background:' . $bg . ';color:' . $text . ';font-family:Segoe UI,system-ui,sans-serif;font-size:14px;padding:10px}'
            . '.wrap{display:flex;flex-direction:column;gap:7px}'
            . '.stats{display:flex;gap:8px;margin-bottom:2px}'
            . '.stat{flex:1;background:' . $card . ';border:1px solid ' . $bord . ';border-radius:12px;padding:8px 4px;text-align:center}'
            . '.stat b{display:block;font-size:24px;font-weight:800;line-height:1}'
            . '.stat span{display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:' . $muted . ';margin-top:3px}'
            . '.s-open b{color:' . $acc . '}.s-ov b{color:' . $red . '}.s-td b{color:' . $ora . '}'
            . '.add-btn{width:100%;background:' . $acc . ';color:#fff;border:none;border-radius:9px;padding:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}'
            . '.row{display:flex;align-items:flex-start;gap:9px;background:' . $card . ';border:1px solid ' . $bord . ';border-radius:11px;padding:11px}'
            . '.row.done{opacity:.45}'
            . '.chk{width:18px;height:18px;margin-top:2px;flex-shrink:0;cursor:pointer;accent-color:' . $acc . '}'
            . '.body{flex:1;min-width:0;cursor:pointer}'
            . '.title{font-weight:700;line-height:1.35;word-break:break-word}'
            . '.done .title{text-decoration:line-through}'
            . '.info{font-size:12px;color:' . $muted . ';margin-top:2px;line-height:1.4}'
            . '.badges{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}'
            . '.badge{font-size:11px;padding:2px 7px;border-radius:999px;border:1px solid ' . $bord . ';color:' . $muted . '}'
            . '.p-h{background:#cc0000;border-color:#cc0000;color:#ffffff;font-weight:700}'
            . '.p-n{border-color:' . $acc . ';color:' . $acc . ';background:rgba(0,205,171,.12)}'
            . '.p-l{border-color:' . $muted . ';color:' . $muted . '}'
            . '.due-ov{border-color:' . $red . ';color:' . $red . ';background:rgba(220,0,0,.18)}'
            . '.due-td{border-color:' . $ora . ';color:' . $ora . ';background:rgba(255,170,64,.12)}'
            . '.del{flex-shrink:0;background:transparent;border:none;color:' . $muted . ';cursor:pointer;font-size:13px;padding:2px 3px;border-radius:5px}'
            . '.divider{font-size:11px;font-weight:700;text-transform:uppercase;color:' . $muted . ';padding:6px 2px 2px}'
            . '.empty{text-align:center;color:' . $muted . ';padding:20px;font-size:13px}'
            . '.ov{display:none;position:fixed;inset:0;background:' . $ov2 . ';z-index:100}'
            . '.ov.open{display:block}'
            . '.modal{display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);'
            . 'width:min(400px,calc(100vw - 24px));max-height:90vh;background:' . $modal . ';'
            . 'border:1px solid ' . $bord . ';border-radius:14px;box-shadow:0 8px 32px ' . $sh . ';'
            . 'z-index:101;flex-direction:column;overflow:hidden}'
            . '.modal.open{display:flex}'
            . '.mh{display:flex;align-items:center;justify-content:space-between;'
            . 'padding:14px 16px 10px;border-bottom:1px solid ' . $bord . ';font-weight:700;font-size:15px}'
            . '.mc{background:transparent;border:none;color:' . $muted . ';cursor:pointer;font-size:15px}'
            . '.mb{padding:14px 16px;display:flex;flex-direction:column;gap:10px;overflow-y:auto}'
            . '.mf{display:flex;gap:7px;padding:10px 16px 14px;border-top:1px solid ' . $bord . ';justify-content:flex-end}'
            . '.lbl{font-size:11px;font-weight:700;text-transform:uppercase;color:' . $muted . ';display:block;margin-bottom:3px}'
            . '.inp{background:' . $inp . ';border:1px solid ' . $bord . ';border-radius:7px;'
            . 'padding:8px 10px;font-size:13px;color:' . $text . ';outline:none;width:100%;font-family:inherit}'
            . '.inp:focus{border-color:' . $acc . '}'
            . '.ta{resize:vertical;min-height:60px;line-height:1.4}'
            . '.r2{display:grid;grid-template-columns:1fr 1fr;gap:10px}'
            . '.btn{display:inline-flex;align-items:center;justify-content:center;border-radius:9px;'
            . 'border:1px solid ' . $bord . ';padding:8px 14px;font-size:13px;font-weight:600;'
            . 'background:' . $card . ';color:' . $text . ';cursor:pointer;font-family:inherit}'
            . '.btn-s{background:' . $acc . ';border-color:' . $acc . ';color:#fff}'
            . '.btn-d{background:' . $red . ';border-color:' . $red . ';color:#fff}';

        $js = 'var H="' . addslashes($fullHook) . '";'
            . 'function post(a,p){fetch(H,{method:"POST",headers:{"Content-Type":"application/json"},'
            . 'body:JSON.stringify({action:a,payload:p||{}})}).catch(function(e){console.error(e);})}  '
            . 'function openAdd(){'
            . 'document.getElementById("eid").value="";'
            . 'document.getElementById("etitle").value="";'
            . 'document.getElementById("einfo").value="";'
            . 'document.getElementById("eprio").value="normal";'
            . 'document.getElementById("edue").value="";'
            . 'document.getElementById("edelbtn").style.display="none";'
            . 'document.getElementById("emtitle").textContent="Neue Aufgabe";'
            . 'document.getElementById("eov").classList.add("open");'
            . 'document.getElementById("emodal").classList.add("open");'
            . 'setTimeout(function(){document.getElementById("etitle").focus();},80);}'
            . 'function openEdit(s){'
            . 'var d;try{d=JSON.parse(s);}catch(e){return;}'
            . 'document.getElementById("eid").value=d.id;'
            . 'document.getElementById("etitle").value=d.title||"";'
            . 'document.getElementById("einfo").value=d.info||"";'
            . 'document.getElementById("eprio").value=d.priority||"normal";'
            . 'if(d.due>0){var dt=new Date(d.due*1000),p=function(n){return String(n).padStart(2,"0");};'
            . 'document.getElementById("edue").value=dt.getFullYear()+"-"+p(dt.getMonth()+1)+"-"+p(dt.getDate())+"T"+p(dt.getHours())+":"+p(dt.getMinutes());}'
            . 'else{document.getElementById("edue").value="";}'
            . 'document.getElementById("edelbtn").style.display="";'
            . 'document.getElementById("emtitle").textContent="Aufgabe bearbeiten";'
            . 'document.getElementById("eov").classList.add("open");'
            . 'document.getElementById("emodal").classList.add("open");'
            . 'setTimeout(function(){document.getElementById("etitle").focus();},80);}'
            . 'function closeModal(){'
            . 'document.getElementById("eov").classList.remove("open");'
            . 'document.getElementById("emodal").classList.remove("open");}'
            . 'function saveModal(){'
            . 'var t=document.getElementById("etitle").value.trim();'
            . 'if(!t){document.getElementById("etitle").style.borderColor="#ff5a5a";document.getElementById("etitle").focus();return;}'
            . 'document.getElementById("etitle").style.borderColor="";'
            . 'var dr=document.getElementById("edue").value;'
            . 'var p={title:t,info:document.getElementById("einfo").value.trim(),'
            . 'priority:document.getElementById("eprio").value,'
            . 'due:dr?Math.floor(new Date(dr).getTime()/1000):0};'
            . 'var id=document.getElementById("eid").value;'
            . 'if(id){p.id=parseInt(id,10);post("UpdateTask",p);}else{post("AddTask",p);}'
            . 'closeModal();}'
            . 'function delModal(){var id=document.getElementById("eid").value;if(id){post("DeleteTask",{id:parseInt(id,10)});closeModal();}}'
            . 'document.addEventListener("keydown",function(e){'
            . 'if(!document.getElementById("emodal").classList.contains("open"))return;'
            . 'if(e.key==="Escape")closeModal();'
            . 'if(e.key==="Enter"&&document.activeElement.tagName!=="TEXTAREA"){e.preventDefault();saveModal();}});';

        $modal_html = '<div id="eov" class="ov" onclick="closeModal()"></div>'
            . '<div id="emodal" class="modal">'
            . '<div class="mh"><span id="emtitle">Neue Aufgabe</span>'
            . '<button class="mc" onclick="closeModal()">&#x2715;</button></div>'
            . '<div class="mb">'
            . '<input type="hidden" id="eid">'
            . '<label class="lbl">Titel *</label>'
            . '<input class="inp" id="etitle" type="text" placeholder="Titel ...">'
            . '<label class="lbl">Beschreibung</label>'
            . '<textarea class="inp ta" id="einfo" placeholder="Notiz ..."></textarea>'
            . '<div class="r2">'
            . '<div><label class="lbl">Priorit&#228;t</label>'
            . '<select class="inp" id="eprio">'
            . '<option value="low">Niedrig</option>'
            . '<option value="normal" selected>Normal</option>'
            . '<option value="high">Hoch</option>'
            . '</select></div>'
            . '<div><label class="lbl">F&#228;llig am</label>'
            . '<input class="inp" id="edue" type="datetime-local"></div>'
            . '</div></div>'
            . '<div class="mf">'
            . '<button class="btn btn-d" id="edelbtn" style="display:none" onclick="delModal()">L&#246;schen</button>'
            . '<button class="btn" onclick="closeModal()">Abbrechen</button>'
            . '<button class="btn btn-s" onclick="saveModal()">Speichern</button>'
            . '</div></div>';

        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>' . $css . '</style></head><body>'
            . '<div class="wrap">' . $stats
            . '<button class="add-btn" onclick="openAdd()">+ Neue Aufgabe</button>'
            . $rows . '</div>'
            . $modal_html
            . '<script>' . $js . '</script>'
            . '</body></html>';
    }

    private function BuildRow($t, $now, $todayE)
    {
        $id    = (int)$t['id'];
        $title = htmlspecialchars((string)(isset($t['title']) ? $t['title'] : ''), ENT_QUOTES, 'UTF-8');
        $info  = htmlspecialchars((string)(isset($t['info'])  ? $t['info']  : ''), ENT_QUOTES, 'UTF-8');
        $done  = !empty($t['done']);
        $prio  = (string)(isset($t['priority']) ? $t['priority'] : 'normal');
        $due   = (int)(isset($t['due']) ? $t['due'] : 0);

        $pc = array('high' => 'p-h', 'normal' => 'p-n', 'low' => 'p-l');
        $pl = array('high' => 'Hoch', 'normal' => 'Normal', 'low' => 'Niedrig');
        $prioClass = isset($pc[$prio]) ? $pc[$prio] : 'p-n';
        $prioLabel = isset($pl[$prio]) ? $pl[$prio] : 'Normal';

        $dueBadge = '';
        if ($due > 0) {
            $dc = ($due < $now) ? ' due-ov' : ($due <= $todayE ? ' due-td' : '');
            $dueBadge = '<span class="badge' . $dc . '">&#128197; ' . date('d.m.y H:i', $due) . '</span>';
        }

        $editData = htmlspecialchars(json_encode(array(
            'id'       => $id,
            'title'    => (string)(isset($t['title']) ? $t['title'] : ''),
            'info'     => (string)(isset($t['info'])  ? $t['info']  : ''),
            'priority' => $prio,
            'due'      => $due
        ), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        return '<div class="row' . ($done ? ' done' : '') . '">'
            . '<input type="checkbox" class="chk"' . ($done ? ' checked' : '')
            . ' onchange="post(\'ToggleDone\',{id:' . $id . ',done:this.checked})">'
            . '<div class="body" onclick="openEdit(\'' . $editData . '\')">'
            . '<div class="title">' . $title . '</div>'
            . ($info !== '' ? '<div class="info">' . $info . '</div>' : '')
            . '<div class="badges">'
            . '<span class="badge ' . $prioClass . '">' . $prioLabel . '</span>'
            . $dueBadge
            . '</div></div>'
            . '<button class="del" onclick="event.stopPropagation();post(\'DeleteTask\',{id:' . $id . '})">&#x2715;</button>'
            . '</div>';
    }

    private function RegisterHook()
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (empty($ids)) {
            IPS_LogMessage('TaskManager', 'Kein WebHook-Control!');
            return;
        }
        $hid   = $ids[0];
        $uri   = '/hook/taskmanager_' . $this->InstanceID;
        $hooks = json_decode(IPS_GetProperty($hid, 'Hooks'), true);
        if (!is_array($hooks)) $hooks = array();
        foreach ($hooks as $h) {
            if ($h['Hook'] === $uri && (int)$h['TargetID'] === $this->InstanceID) return;
        }
        $hooks[] = array('Hook' => $uri, 'TargetID' => $this->InstanceID);
        IPS_SetProperty($hid, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hid);
    }

    private function LoadTasks()
    {
        $data = json_decode($this->ReadAttributeString('Tasks'), true);
        return is_array($data) ? $data : array();
    }

    private function SaveTasks($tasks)
    {
        $this->WriteAttributeString('Tasks', json_encode(array_values($tasks)));
    }

    private function ValidPrio($p)
    {
        return in_array($p, array('low', 'normal', 'high')) ? $p : 'normal';
    }
}
