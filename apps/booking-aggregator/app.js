(function(){
'use strict';

/* ══════════ КОНСТАНТЫ ══════════ */

var API=(window.PSY_API||'api.php');
var ROLE=(window.PSY_ROLE||'client');
var S={
  view:'home', smsLog:null, editFor:null, mTopic:'', mExpert:'', mDt:'', mName:'', mPhone:'', mTg:'', mPair:false, histFor:null, histLog:null, doneA:null, doneLabel:'', topic:'', pair:null, wantDir:'', pickDir:'', pickedExpert:null, expSearch:'', topicPick:'', ver:null, stale:false, topicDraft:null, renameFor:null, clients:null, cliSearch:'', cliFlt:'all', cliOpen:null, stats:null, statMonth:null,
  tFrom:'', tTo:'', tStep:'', boot:null, err:'',
  pickedSlot:null, lastBooking:null,
  codeView:null, codeErr:'', moveMode:false, lastAdminBooking:null,
  exp:null, adm:null, adminTab:'dash',
  draftDate:'', draftTimes:[], halfHours:false, repeat:1,
  busy:false
};
var app=document.getElementById('psy-app');
var poll=null;

function startView(){
  if(ROLE==='expert') return tok('expert')?'expert':'expertLogin';
  if(ROLE==='admin')  return tok('admin') ?'admin':'adminLogin';
  return 'home';
}
function tok(role){ try{ return localStorage.getItem('psy_tok_'+role)||''; }catch(e){ return ''; } }
function setTok(role,v){ try{ v?localStorage.setItem('psy_tok_'+role,v):localStorage.removeItem('psy_tok_'+role); }catch(e){} }

/* ══════════ API ══════════ */
function api(action,body,role){
  var opt={method:body?'POST':'GET',headers:{}};
  var url='api.php?action='+action;
  var t=role?tok(role):'';
  if(t){
    opt.headers['Authorization']='Bearer '+t;      /* основной путь */
    if(body) body=Object.assign({},body,{token:t}); /* запасной: если Apache срежет заголовок */
    else url+='&token='+encodeURIComponent(t);
  }
  if(body){ opt.headers['Content-Type']='application/json'; opt.body=JSON.stringify(body); }
  return fetch(API+url.slice(7),opt).then(function(r){
    return r.json().catch(function(){ throw new Error('Сервер ответил не по формату'); }).then(function(j){
      if(!j||!j.ok){
        var e=new Error((j&&j.error)||('Ошибка '+r.status)); e.status=r.status; throw e;
      }
      return j;
    });
  });
}

/* ══════════ УТИЛИТЫ ══════════ */
function esc(s){
  return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}
function dt(v){ return new Date(String(v).replace(' ','T')); }
function fmtDay(v){
  var d=dt(v);
  var s=d.toLocaleDateString('ru-RU',{weekday:'long',day:'numeric',month:'long'});
  return s.charAt(0).toUpperCase()+s.slice(1);
}
function fmtTime(v){ return String(v).slice(11,16); }
function fmtFull(v){ return fmtDay(v)+', '+fmtTime(v); }
function nowMs(){ return Date.now()+(S.skew||0); }
function isPast(v){ return dt(v).getTime()<nowMs(); }
function todayISO(){
  var d=new Date(),p=function(n){return (n<10?'0':'')+n;};
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate());
}
function plural(n,f){
  n=Math.abs(n)%100; var n1=n%10;
  if(n>10&&n<20) return f[2];
  if(n1>1&&n1<5) return f[1];
  if(n1===1) return f[0];
  return f[2];
}
function toast(msg){
  var t=document.createElement('div');
  t.className='toast'; t.textContent=msg;
  document.body.appendChild(t);
  setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); },3600);
}
function metric(v,l,hot){ return '<div class="metric'+(hot?' hot':'')+'"><b>'+v+'</b><span>'+l+'</span></div>'; }
/* Одно состояние записи на все экраны, чтобы цифры в дашборде и в отчётах сходились.
   'done'   — реально прошла: специалист сдал отчёт и отметил «провели»;
   'noshow' — клиент не пришёл, 'cancelled' — отменена (обе — «не прошла»);
   'await'  — время прошло, а отчёта нет: пока непонятно, чем кончилось;
   'ahead'  — время ещё впереди. */
function bkState(b){
  if(b.status==='cancelled') return 'cancelled';
  if(b.status==='noshow')    return 'noshow';
  if(b.status==='held' && b.rep_at) return 'done';
  return isPast(b.dt)?'await':'ahead';
}
function inState(list, st){ return list.filter(function(b){ return bkState(b)===st; }); }
/* Время встречи прошло и её не отменяли — сюда входят и те, по кому отчёта ещё нет */
function passedOf(list){ return list.filter(function(b){ var x=bkState(b); return x==='done'||x==='noshow'||x==='await'; }); }
/* Не прошли: отменённые и неявки — одной корзиной */
function failedOf(list){ return list.filter(function(b){ var x=bkState(b); return x==='cancelled'||x==='noshow'; }); }
function avgOf(list, f){
  var v=list.map(f).filter(function(x){ return x!==null&&x!==undefined&&x!==''; }).map(Number);
  return v.length ? v.reduce(function(a,b){return a+b;},0)/v.length : null;
}
/* Клиентке внутренние формулировки не нужны: «ждём отчёта» — это про нашу кухню */
function clientStatusTag(b){
  var st=bkState(b);
  if(b.status==='searching') return '<span class="tag wait">подбираем специалиста</span>';
  if(st==='cancelled') return '<span class="tag">отменена</span>';
  if(st==='done')      return '<span class="tag ok">состоялась</span>';
  if(st==='noshow')    return '<span class="tag bad">отмечена как несостоявшаяся</span>';
  if(st==='await')     return dt(b.dt).getTime()+(b.duration||50)*60000 > nowMs()
    ? '<span class="tag wait">идёт сейчас</span>' : '<span class="tag wait">время прошло</span>';
  return '<span class="tag ok">запланирована</span>';
}
function statusTag(b){
  if(b.status==='searching') return '<span class="tag bad">ищем специалиста</span>';
  var st=bkState(b);
  if(st==='done')      return '<span class="tag ok">состоялась</span>';
  if(st==='noshow')    return '<span class="tag bad">не пришёл</span>';
  if(st==='cancelled') return '<span class="tag">отменена</span>';
  if(st==='await')     return '<span class="tag wait">ждём отчёта</span>';
  return '<span class="tag">запланирована</span>';
}
function val(id){ var e=document.getElementById(id); return e?String(e.value||'').trim():''; }

/* ══════════ ЗАГРУЗКА ══════════ */
function boot(){
  return api('boot').then(function(j){ noteVer(j); S.boot=j; if(j.now) S.skew=dt(j.now).getTime()-Date.now(); }).catch(function(e){ S.err=e.message; });
}
function loadExpert(){
  return api('expert_state',null,'expert').then(function(j){ noteVer(j); S.exp=j; })
    .catch(function(e){ if(e.status===401){ setTok('expert',''); S.exp=null; S.view='expertLogin'; } else toast(e.message); });
}
function loadAdmin(){
  return api('admin_state',null,'admin').then(function(j){ noteVer(j); S.adm=j; })
    .catch(function(e){ if(e.status===401){ setTok('admin',''); S.adm=null; S.view='adminLogin'; } else toast(e.message); });
}

/* ══════════ РЕНДЕР ══════════ */
/* Вкладка могла провисеть открытой через выкладку: тогда она рисует старые формы
   и шлёт на сервер устаревшие данные. Замечаем это и предлагаем обновиться. */
function noteVer(j){
  if(!j || !j.ver) return;
  if(!S.ver){ S.ver=j.ver; return; }
  if(String(j.ver)!==String(S.ver)) S.stale=true;
}
function staleBar(){
  return S.stale
    ? '<div class="note warn" style="margin-bottom:14px"><b>Страница устарела.</b> '+
      'Пока она была открыта, вышло обновление — в ней могут быть старые списки и кнопки. '+
      '<button class="btn-ghost btn-sm" data-a="reload" style="margin-left:8px">Обновить страницу</button></div>'
    : '';
}
function render(){
  var body='';
  if(S.err && !S.boot) body='<div class="card"><h2>Сервер недоступен</h2><p class="muted" style="margin-top:8px">'+esc(S.err)+
    '</p><button class="btn" data-a="reload">Попробовать снова</button></div>';
  else if(S.view==='home')        body=vHome();
  else if(S.view==='client')      body=vClient();
  else if(S.view==='done')        body=vDone();
  else if(S.view==='code')        body=vCode();
  else if(S.view==='expertLogin') body=vExpertLogin();
  else if(S.view==='expert')      body=S.exp?vExpert():'<div class="card"><div class="empty">Загружаем кабинет…</div></div>';
  else if(S.view==='adminLogin')  body=vAdminLogin();
  else if(S.view==='admin')       body=S.adm?vAdmin():'<div class="card"><div class="empty">Загружаем дашборд…</div></div>';

  var wide=(S.view==='admin')?' wide':'';
  app.innerHTML='<div class="wrap'+wide+'">'+head()+staleBar()+body+'</div>';
  bind();
}
function head(){
  var name=(S.boot&&S.boot.org)||'Психология и коучинг';
  var tail={client:'запись',expert:'кабинет специалиста',admin:'координатор'}[ROLE]||'запись';
  var atStart=(S.view==='home'||S.view==='client'||S.view==='expertLogin'||S.view==='adminLogin');
  var back=atStart?'':'<button class="btn-ghost btn-sm" data-a="home">← В начало</button>';
  return '<div class="topbar"><div class="brandmark"><i class="dot"></i><span>'+esc(name)+' — '+tail+'</span></div>'+back+'</div>';
}

/* ---------- 1. Вход ---------- */
function codeCard(){
  return '<div class="card" style="margin-top:14px"><div class="split">'+
    '<div><h3>Уже записались?</h3><p class="muted" style="margin:6px 0 0">Введите код записи, чтобы посмотреть встречу, перенести её или оставить отзыв.</p></div>'+
    '<div style="flex:0 0 190px"><input type="text" id="codeIn" placeholder="Например, K7QM2" maxlength="6" style="text-transform:uppercase;margin-bottom:8px">'+
    '<button class="btn btn-sm" data-a="openCode" style="width:100%">Открыть запись</button></div>'+
  '</div></div>';
}
function vHome(){ return vClient()+codeCard(); }

/* ---------- 2. Клиент ---------- */
function vClient(){
  var times=(S.boot&&S.boot.times)||[];
  var topics=(S.boot&&S.boot.topics)||[];

  /* Приём закрыт: новых записей не принимаем, но уже назначенные встречи в силе. */
  if(S.boot&&S.boot.closed)
    return '<div class="card"><h2>Запись на консультации закрыта</h2>'+
      '<p class="muted" style="margin-top:10px">Сейчас мы не принимаем новые записи. '+
      'Если у вас <b>уже назначена встреча</b> — она состоится в прежнее время, ссылка придёт как обычно. '+
      'Проверить или перенести свою запись можно по коду ниже.</p></div>';

  /* Шаг 1 — тема. Пока она не выбрана, календарь не показываем. */
  if(!S.topic){
    var h='<div class="card"><div class="eyebrow">Шаг 1 — тема</div><h2>С чем вы хотите поработать?</h2>'+
      '<p class="muted" style="margin-top:8px">Выберите тему — покажем время только тех специалистов, кто с ней работает. '+
      'Подробности расскажете на встрече, здесь достаточно направления мысли.</p></div>';
    if(!topics.length)
      return h+'<div class="card"><div class="empty">Не удалось загрузить список тем.<br>Обновите страницу — обычно помогает.</div></div>';
    var groups=(S.boot&&S.boot.topicGroups)||[{title:'',items:topics}];
    /* Тем много, поэтому список свёрнут в один выпадающий: разделы — заголовками внутри него */
    var pick=S.topicPick||'';
    h+='<div class="card"><label for="topicSel">Ваш запрос</label>'+
       '<select id="topicSel"><option value="">— выберите из списка —</option>';
    groups.forEach(function(g){
      var items=(g.items||[]).filter(function(t){ return !topics.length || topics.indexOf(t)>=0; });
      if(!items.length) return;
      h+='<optgroup label="'+esc(g.title||'Темы')+'">';
      items.forEach(function(t){
        /* Сколько именно у нас слотов — внутренняя кухня: клиентке важно только,
           есть ли время по её теме. Числа наружу не показываем. */
        var n=times.filter(function(x){return (x.topics||[]).indexOf(t)>=0;}).length;
        h+='<option value="'+esc(t)+'"'+(pick===t?' selected':'')+'>'+esc(t)+(n?'':' — сейчас нет свободного времени')+'</option>';
      });
      h+='</optgroup>';
    });
    h+='</select>';
    if(pick){
      var free=times.filter(function(x){return (x.topics||[]).indexOf(pick)>=0;}).length;
      h+='<div class="field-hint" style="margin-bottom:12px">'+(free
        ? 'По этому запросу есть свободное время — покажем его на следующем шаге.'
        : 'По этому запросу сейчас нет свободного времени. Выберите близкую тему или загляните позже — расписание пополняется.')+'</div>';
    }
    h+='<button class="btn" data-a="topicGo"'+(pick?'':' disabled')+'>Дальше</button></div>';
    return h;
  }

  /* подходит ли специалист под выбранное */
  function fitEx(x,opts){
    if((x.t||[]).indexOf(S.topic)<0) return false;
    if(opts.pair) return !!x.p && x.g==='any';
    if(opts.gender && x.g!=='any' && x.g!==opts.gender) return false;
    return true;
  }
  function timesFor(opts){
    return times.filter(function(t){ return (t.ex||[]).some(function(x){ return fitEx(x,opts); }); });
  }

  /* Шаг 2 — формат: одна или вдвоём. Парные берут не все специалисты. */
  if(S.pair===null||S.pair===undefined){
    var canPair=timesFor({pair:true}).length>0;
    var h2='<div class="card"><div class="split"><div><div class="eyebrow">Шаг 2 — формат</div>'+
      '<h2>Вы придёте одна или вдвоём?</h2></div>'+
      '<button class="btn-ghost btn-sm" data-a="topicBack">Другая тема</button></div>'+
      '<p class="muted" style="margin-top:10px">Парную консультацию ведут не все специалисты, поэтому от ответа зависит, '+
      'какое время мы покажем.</p></div>';
    h2+='<div class="roles" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">'+
      '<button class="role" data-a="pair" data-v="0"><strong>Одна</strong><em>Индивидуальная консультация</em></button>'+
      (canPair
        ? '<button class="role" data-a="pair" data-v="1"><strong>Вдвоём</strong><em>Парная консультация — придём с партнёром</em></button>'
        : '<div class="role" style="opacity:.55"><strong>Вдвоём</strong><em>По этой теме сейчас нет свободного времени у специалистов, которые ведут пары</em></div>')+
      '</div>';
    return h2;
  }

  /* Шаг 3 — пол: только для индивидуальной; в паре специалист должен работать со всеми */
  if(S.pair==='0' && !S.gender){
    var hasF=timesFor({gender:'f'}).length, hasM=timesFor({gender:'m'}).length;
    var hg='<div class="card"><div class="split"><div><div class="eyebrow">Шаг 3 — о вас</div>'+
      '<h2>Кто пойдёт на встречу?</h2></div>'+
      '<button class="btn-ghost btn-sm" data-a="pairBack">Назад</button></div>'+
      '<p class="muted" style="margin-top:10px">Часть специалистов работает только с женщинами или только с мужчинами — '+
      'поэтому спрашиваем заранее, чтобы показать вам подходящее время.</p></div>';
    hg+='<div class="roles" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">'+
      (hasF?'<button class="role" data-a="gender" data-v="f"><strong>Женщина</strong><em>есть свободное время</em></button>'
           :'<div class="role" style="opacity:.55"><strong>Женщина</strong><em>по этой теме сейчас нет свободного времени</em></div>')+
      (hasM?'<button class="role" data-a="gender" data-v="m"><strong>Мужчина</strong><em>есть свободное время</em></button>'
           :'<div class="role" style="opacity:.55"><strong>Мужчина</strong><em>по этой теме сейчас нет специалистов, работающих с мужчинами</em></div>')+
      '</div>';
    return hg;
  }

  var shown=timesFor({pair:S.pair==='1', gender:S.gender});
  hit('time');
  var h='<div class="card"><div class="split"><div><div class="eyebrow">Шаг 4 — время</div>'+
    '<h2>'+esc(S.topic)+'</h2><p class="muted" style="margin-top:4px">'+
    (S.pair==='1'?'Парная консультация':'Индивидуальная'+(S.gender==='m'?' · мужчина':S.gender==='f'?' · женщина':''))+
    ' · <button class="btn-link" data-a="pairBack">изменить</button></p></div>'+
    '<button class="btn-ghost btn-sm" data-a="topicBack">Другая тема</button></div>'+
    '<p class="muted" style="margin-top:10px">Специалиста подберём мы: свободного на это время, работающего с вашей темой и с наименьшей загрузкой. '+
    'Имя и ссылку на встречу увидите сразу после записи. Время '+esc(tzName())+'.</p></div>';

  if(!shown.length){
    return h+'<div class="card"><div class="empty">'+
      (S.pair==='1'?'По этой теме сейчас нет времени у специалистов, которые ведут парные консультации.<br>Загляните позже или выберите другую тему.'
                   :'По этой теме сейчас нет подходящего свободного времени.<br>Загляните позже или выберите другую тему — расписание пополняется ежедневно.')+
      '</div></div>';
  }
  var days={},order=[];
  shown.forEach(function(t){ var d=String(t.dt).slice(0,10); if(!days[d]){days[d]=[];order.push(d);} days[d].push(t); });

  h+='<div class="note" style="margin-bottom:14px"><b>Всё время — '+esc(tzName())+'.</b> '+
     'Если вы в другом часовом поясе, пересчитайте заранее: встреча пройдёт именно в это время.</div>';
  h+='<div class="card">';
  order.forEach(function(d){
    h+='<div class="day"><div class="day-head"><b>'+esc(fmtDay(days[d][0].dt))+'</b>'+
       '<span>'+esc(tzShort())+'</span></div><div class="tickets">';
    days[d].forEach(function(t){
      h+='<button class="ticket" aria-pressed="'+(S.pickedSlot===t.dt)+'" data-a="pick" data-t="'+esc(t.dt)+'">'+
         fmtTime(t.dt)+'<small>'+S.boot.duration+' мин</small></button>';
    });
    h+='</div></div>';
  });
  h+='</div>';

  if(S.pickedSlot){
    hit('form');
    h+='<div class="card"><div class="eyebrow">Шаг 5 — как с вами связаться</div>'+
      '<h2>'+esc(fmtFull(S.pickedSlot))+'</h2>'+
      '<p class="muted" style="margin-top:4px">'+esc(S.topic)+' · '+(S.pair==='1'?'парная консультация':'индивидуальная')+'</p>'+
      '<div style="height:16px"></div>'+
      '<label for="cName">'+(S.pair==='1'?'Ваши имена':'Как к вам обращаться')+'</label>'+
      '<input type="text" id="cName" placeholder="'+(S.pair==='1'?'например: Сергей и Мария':'Имя')+'" autocomplete="given-name">'+
      '<label for="cContact">Telegram или телефон</label>'+
      '<input type="text" id="cContact" placeholder="@nickname или +7…">'+
      '<label for="cMail">Почта — по желанию</label>'+
      '<input type="text" id="cMail" placeholder="вы@example.com" autocomplete="email">'+
      '<div class="field-hint">Напоминания придут в Telegram, а на почту — подтверждение со ссылкой на встречу. Хотя бы один канал стоит оставить.</div>'+
      '<label for="cNote">Опишите запрос парой слов</label>'+
      '<textarea id="cNote" maxlength="300" placeholder="Одна-две фразы — чтобы специалист пришёл на встречу подготовленным."></textarea>'+
      '<div class="note">Не описывайте здесь детали здоровья и личной жизни. Это открытая форма записи — подробный разговор ждёт вас на самой встрече, наедине со специалистом.</div>'+
      '<label style="font-weight:400;display:flex;gap:9px;align-items:flex-start;margin-bottom:16px">'+
      '<input type="checkbox" id="cAgree" style="width:auto;margin:3px 0 0"><span style="font-size:13px">Согласна на обработку моих данных для организации встречи</span></label>'+
      '<button class="btn" data-a="book"'+(S.busy?' disabled':'')+'>'+(S.busy?'Записываем…':'Записаться на '+fmtTime(S.pickedSlot))+'</button></div>';
  }
  return h;
}

function tzShort(){
  var tz=(S.boot&&S.boot.tz)||'Europe/Moscow';
  return tz==='Europe/Moscow'?'мск':tz;
}
function tzName(){
  var tz=(S.boot&&S.boot.tz)||'Europe/Moscow';
  return tz==='Europe/Moscow'?'московское':('в часовом поясе '+tz);
}

function vDone(){
  var b=S.lastBooking; if(!b){ S.view='home'; return vHome(); }
  var h='<div class="card"><div class="eyebrow">Готово</div><h1>Вы записаны</h1><div style="height:18px"></div>'+
    '<table><tr><th style="width:150px">Когда</th><td><b>'+esc(fmtFull(b.dt))+'</b> <span class="tag">'+esc(tzShort())+'</span></td></tr>'+
    '<tr><th>Специалист</th><td>'+esc(b.expert)+'</td></tr>'+
    '<tr><th>Ссылка</th><td>'+(b.link?'<a href="'+esc(b.link)+'" target="_blank" rel="noopener">'+esc(b.link)+'</a>':'<span class="tag wait">координатор пришлёт ссылку</span>')+'</td></tr>'+
    '<tr><th>Код записи</th><td><span class="code">'+esc(b.code)+'</span></td></tr></table>';
  if(b.tg){
    h+='<div style="height:20px"></div>'+
      '<a class="btn-tg" href="'+esc(b.tg)+'" target="_blank" rel="noopener">Включить напоминания в Telegram</a>'+
      '<div class="field-hint" style="margin-top:10px">Один тап — и бот пришлёт подтверждение со ссылкой, напомнит за сутки и за два часа, '+
      'а после встречи спросит, как всё прошло. Без этого напоминания придётся слать вручную.</div>';
  }
  h+='<div class="note" style="margin-top:14px">Сохраните код — по нему можно открыть запись, перенести или отменить её и оставить отзыв.</div>'+
    '<div class="field-hint" style="margin-top:10px">Если в назначенный час специалиста нет на месте — подождите 10 минут и перенесите '+
    'встречу по этому же коду на другое время. Такое изредка случается: интернет, накладка по времени. Место мы за вами сохраним.</div>'+
    '<button class="btn-ghost" data-a="home">В начало</button></div>';
  return h;
}

/* ---------- 3. Запись по коду: просмотр, отмена, отзыв ---------- */
function vCode(){
  var b=S.codeView;
  if(!b) return '<div class="card"><h2>Запись не найдена</h2><p class="muted" style="margin-top:8px">'+
    esc(S.codeErr||'Проверьте код — пять символов из подтверждения.')+'</p><button class="btn-ghost" data-a="home">Назад</button></div>';

  var over=dt(b.dt).getTime()+(b.duration||50)*60000 < nowMs();
  var h='<div class="card"><div class="eyebrow">Ваша запись</div><h2>'+esc(fmtFull(b.dt))+' <span class="tag" style="vertical-align:middle">'+esc(tzShort())+'</span></h2><div style="height:14px"></div>'+
    '<table><tr><th style="width:140px">Специалист</th><td>'+esc(b.expert)+'</td></tr>'+
    '<tr><th>Ссылка</th><td>'+(b.link?'<a href="'+esc(b.link)+'" target="_blank" rel="noopener">Открыть встречу</a>':'уточняется')+'</td></tr>'+
    '<tr><th>Статус</th><td>'+clientStatusTag(b)+'</td></tr></table>';
  if(b.tg && !over) h+='<div style="height:16px"></div><a class="btn-tg" href="'+esc(b.tg)+'" target="_blank" rel="noopener">Напоминания в Telegram</a>';
  var canMove=(b.canMove!==false) && b.status!=='cancelled';
  if(canMove)
    h+='<div style="height:16px"></div>'+
       '<button class="btn-ghost btn-sm" data-a="moveStart">Перенести на другое время</button> '+
       '<button class="btn-link" data-a="cancelCode" style="margin-left:10px">Отменить запись</button>';
  /* Специалист живой человек: может пропасть интернет, может наложиться другая встреча.
     Клиентке важно заранее знать, что ждать бесконечно не нужно. */
  if(canMove)
    h+='<div class="note'+(b.late?' warn':'')+'" style="margin-top:16px">'+
       (b.late?'<b>'+esc(b.late)+'</b> ':'<b>Специалиста нет на встрече?</b> ')+
       'Такое случается — пропал интернет, сдвинулась предыдущая встреча. '+
       'Не ждите дольше <b>10 минут</b>: нажмите «Перенести на другое время» и выберите новый час. '+
       'Ваше место остаётся за вами, пока вы не выберете новое, и переносить можно даже после начала встречи.</div>';
  h+='</div>';

  if(S.moveMode && canMove){
    var times=(S.boot&&S.boot.times)||[];
    h+='<div class="card"><div class="eyebrow">Перенос</div><h3>Выберите новое время</h3>'+
       '<p class="muted" style="margin:8px 0 14px">Старое место держим за вами, пока вы не выберете новое. '+
       'Если специалист свободен в новый час — останется он же.</p>';
    if(!times.length) h+='<div class="empty">Сейчас нет других свободных слотов.<br>Загляните позже — расписание пополняется.</div>';
    else{
      var days={},order=[];
      times.forEach(function(t){ var d=String(t.dt).slice(0,10); if(!days[d]){days[d]=[];order.push(d);} days[d].push(t); });
      order.forEach(function(d){
        h+='<div class="day"><div class="day-head"><b>'+esc(fmtDay(days[d][0].dt))+'</b></div><div class="tickets">';
        days[d].forEach(function(t){
          h+='<button class="ticket" data-a="moveTo" data-t="'+esc(t.dt)+'">'+fmtTime(t.dt)+'<small>перенести сюда</small></button>';
        });
        h+='</div></div>';
      });
    }
    h+='<button class="btn-link" data-a="moveCancel">Передумала, оставить как было</button></div>';
    return h;
  }

  if(b.status==='cancelled') return h+'<div class="card"><div class="empty">Запись отменена.<br>Хотите вернуться — выберите новое время на главной.</div></div>';
  if(!over) return h+'<div class="card"><div class="empty">Отзыв можно оставить после встречи.</div></div>';
  if(b.csat) return h+'<div class="card"><div class="empty">Спасибо, отзыв получен.<br>Оценка: '+esc(b.csat)+' из 5.</div></div>';

  var stars='';
  for(var s=1;s<=5;s++) stars+='<button class="star" data-a="star" data-v="'+s+'" aria-pressed="false">'+s+'</button>';
  return h+'<div class="card">'+
    (canMove?'<div class="note warn" style="margin-bottom:16px"><b>А если встреча не состоялась?</b> '+
      'Специалист не подключился — бывает. Нажмите «Перенести на другое время» выше и выберите новый час, '+
      'оценку тогда оставлять не нужно.</div>':'')+
    '<div class="eyebrow">Отзыв</div><h2>Как прошла встреча?</h2><div style="height:16px"></div>'+
    '<label>Оценка встречи</label><div class="stars">'+stars+'</div>'+
    '<label for="fFit">Специалист вам подошёл?</label>'+
    '<select id="fFit"><option>Да</option><option>Скорее да</option><option>Скорее нет</option><option>Нет</option></select>'+
    '<label for="fNps">Насколько вероятно, что порекомендуете нас подруге? 0–10</label>'+
    '<input type="number" id="fNps" min="0" max="10" value="8">'+
    '<label for="fCom">Что скажете своими словами</label>'+
    '<textarea id="fCom" placeholder="По желанию"></textarea>'+
    '<button class="btn" data-a="sendCsat">Отправить отзыв</button></div>';
}

/* ---------- 4. Кабинет специалиста ---------- */
function vExpertLogin(){
  var list=(S.boot&&S.boot.experts)||[];
  if(!list.length) return '<div class="card"><h2>Список пуст</h2><p class="muted" style="margin-top:8px">Координатор загружает список из общей таблицы школы.</p></div>';
  var opts=list.map(function(e){return '<option value="'+e.id+'">'+esc(e.name)+'</option>';}).join('');
  return '<div class="card"><div class="eyebrow">Кабинет специалиста</div><h2>Выберите себя</h2>'+
    '<div style="height:16px"></div>'+
    '<label for="eSel">Ваше имя</label><select id="eSel">'+opts+'</select>'+
    '<button class="btn" data-a="eLogin">Войти</button></div>';
}

function vExpert(){
  var D=S.exp, e=D.expert;
  var slots=D.slots||[], bk=D.bookings||[];
  var freeSlots=slots.filter(function(s){return !s.booking_id && !isPast(s.dt);});
  var upcoming=bk.filter(function(b){return !isPast(b.dt) && b.status!=='cancelled';});
  var past=bk.filter(function(b){return isPast(b.dt) && b.status!=='cancelled';});
  var noRep=past.filter(function(b){return !b.rep_at;});

  /* Пока бот не подключён, человек узнаёт о записях последним — поэтому просьба
     стоит выше всего остального и не прячется в мелкий текст. */
  var h='';
  if(!e.tgBound && D.tgLink)
    h+='<div class="card" style="background:#FBEEEA;border-left:4px solid var(--alarm)">'+
       '<div class="eyebrow" style="color:var(--alarm)">Сделайте это первым</div>'+
       '<h2 style="margin:4px 0 10px">Подключите уведомления в Telegram</h2>'+
       '<p style="margin:0 0 16px">Пока бот не подключён, вы не узнаете о новой записи, не получите '+
       'напоминание за два часа и ссылку на встречу — всё это придётся смотреть здесь руками. '+
       'Подключение занимает одно нажатие.</p>'+
       '<a class="btn" href="'+esc(D.tgLink)+'" target="_blank" rel="noopener">Подключить Telegram</a>'+
       '<p class="muted" style="margin:14px 0 0">Откроется бот школы — нажмите в нём «Запустить». '+
       'Если бот вас не узнал, попросите у координатора ваш ключ доступа и отправьте его боту одним сообщением.</p>'+
       '</div>';
  h+='<div class="card"><div class="split"><div><div class="eyebrow">Кабинет</div><h1>'+esc(e.name)+'</h1></div>'+
    '<button class="btn-ghost btn-sm" data-a="eLogout">Выйти</button></div>'+
    '<div class="metrics" style="margin-top:18px">'+
    metric(freeSlots.length,'Открытых слотов')+
    metric(inState(past,'done').length,'Реально прошли')+
    metric(noRep.length,'Отчётов не сдано',noRep.length>0)+
    '</div>';
  if(!e.link) h+='<div class="note warn"><b>Добавьте ссылку на свою комнату для встреч.</b> Без неё время не открывается '+
    'и клиентки вас не видят: приходить им некуда. Поле — ниже, в разделе «Профиль».</div>';
  h+='</div>';
  var rulesOk=!!e.rulesAt, gate=!!D.rulesGate;
  if(!rulesOk && gate)
    h+='<div class="note warn" style="margin-bottom:14px"><b>Ваше время пока не показывается клиенткам.</b> '+
       'Прочитайте четыре договорённости и то, как устроены деньги, — и поставьте галочку внизу. '+
       'После этого ваши открытые часы сразу появятся в записи.</div>';

  h+='<div class="card"'+((!rulesOk&&gate)?' style="border-color:var(--alarm)"':'')+'><div class="eyebrow">Как мы работаем вместе</div>'+
    '<div style="height:6px"></div>'+
    '<table style="margin:0">'+
    '<tr><th style="width:32px;padding-top:8px">1</th><td><b>Вы сами управляете своим временем.</b> '+
      'Клиентки видят только те часы, которые вы открыли. Открыли больше слотов — вероятность записи вырастает, '+
      'не открыли ни одного — система при всём желании никого не приведёт.</td></tr>'+
    '<tr><th style="padding-top:8px">2</th><td><b>Отчёт лучше писать сразу.</b> '+
      'Пока его нет, система не назначает вам новых клиенток.</td></tr>'+
    '<tr><th style="padding-top:8px">3</th><td><b>Планы меняются — просто предупредите.</b> '+
      'Заболели, накладка, не рассчитали силы. Свободный час снимите крестиком, а если время уже занято — '+
      'отмените встречу, чтобы клиентке успели подобрать другого специалиста.</td></tr>'+
    '<tr><th style="padding-top:8px">4</th><td><b>Главное — не оставлять человека одного.</b> '+
      'Если вас нет и вы не предупредили, клиентка сидит перед экраном десять минут и переносит встречу сама. '+
      'Мы это видим. Первый раз — просто спросим, что случилось, всякое бывает. '+
      'Если повторяется — поставим показ вашего времени на паузу.</td></tr>'+
    '</table></div>';

  h+='<div class="card"><div class="eyebrow">Деньги</div><div style="height:6px"></div>'+
    '<table style="margin:8px 0 0">'+
    '<tr><td><b>Первая встреча — бесплатное знакомство.</b> За неё вы не получаете оплаты ни от школы, '+
      'ни от клиентки: это ваше вложение в то, чтобы человек захотел остаться.</td></tr>'+
    '<tr><td><b>Дальше — ваша частная практика.</b> Формат, количество встреч и цену вы определяете сами, '+
      'и клиентка платит напрямую вам, на ваши реквизиты.</td></tr>'+
    '<tr><td><b>Школа не участвует в расчётах</b> и процента с вашей работы не берёт. Она приводит вам '+
      'клиенток — всё, что после первой встречи, ваше.</td></tr>'+
    '<tr><td><b>Чек выставляете вы.</b> По оферте нужен статус самозанятой или ИП — деньги вы принимаете сами.</td></tr>'+
    '<tr><td><b>Договориться о продолжении — тоже ваша часть.</b> В конце встречи скажите, что увидели, '+
      'что предлагаете делать и сколько это стоит. Отправлять клиентку к куратору не нужно.</td></tr>'+
    '</table>'+
    (rulesOk
      ? '<div class="note" style="margin-top:14px"><span class="tag ok">принято</span> '+
        'Вы подтвердили договорённости '+esc(String(e.rulesAt).slice(0,16).replace("T"," "))+'. '+
        'Ваше время показывается клиенткам.</div>'
      : '<div style="margin-top:16px;padding-top:14px;border-top:1px solid #EFE7DB">'+
        '<label style="font-weight:400;display:flex;gap:9px;align-items:flex-start;margin-bottom:12px">'+
        '<input type="checkbox" id="rulesBox" style="width:auto;margin:3px 0 0">'+
        '<span style="font-size:14px"><b>Прочитала и согласна</b><br>'+
        '<span class="muted">Четыре договорённости и то, как устроены деньги, мне понятны.</span></span></label>'+
        '<button class="btn" data-a="acceptRules">Подтвердить</button>'+
        (gate?'<div class="field-hint" style="margin-top:10px">Пока галочка не поставлена, ваши часы клиенткам не показываются.</div>':'')+
        '</div>')+
    '</div>';

  /* Темы и парные консультации. Если у клиентки этих шагов нет, то и
     отмечать здесь нечего — блок только сбивал бы с толку. */
  /* Черновик: пока специалист не нажал «Сохранить», его галочки живут здесь и
     переживают любую перерисовку — раньше их стирал любой соседний запрос. */
  /* Пока темы не отмечены, не показываем все галочки поставленными: иначе человек
     снимает пару лишних и сохраняет почти всё — и потом получает чужие запросы. */
  var mine=S.topicDraft||(e.topicsSet?(e.topics||[]):[]), all=(D.allTopics||[]);
  var tgroups=(D.topicGroups||[{title:'',items:all}]);
  h+='<div class="card"><div class="eyebrow">Профиль</div><h3>С какими темами вы работаете</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Клиентка выбирает конкретный запрос, а не общее направление, '+
    'и видит время только тех, кто с этим работает. Чем честнее отметите, тем меньше придёт «не ваших» запросов. '+
    'Можно отметить весь раздел одной кнопкой.</p>';
  tgroups.forEach(function(g,gi){
    var items=(g.items||[]);
    if(!items.length) return;
    var allOn=items.every(function(t){ return mine.indexOf(t)>=0; });
    h+='<div style="padding:12px 0;border-top:1px solid #EFE7DB">'+
       '<div class="split" style="margin-bottom:10px"><b style="font-size:14px">'+esc(g.title||'Темы')+'</b>'+
       '<button class="btn-link" data-a="topicGroup" data-v="'+gi+'" data-on="'+(allOn?'0':'1')+'">'+
       (allOn?'снять весь раздел':'отметить весь раздел')+'</button></div>';
    items.forEach(function(t){
      h+='<label style="font-weight:400;display:flex;gap:9px;align-items:flex-start;margin-bottom:9px">'+
         '<input type="checkbox" class="topicBox" data-g="'+gi+'" value="'+esc(t)+'" style="width:auto;margin:3px 0 0"'+
         (mine.indexOf(t)>=0?' checked':'')+'><span style="font-size:14px">'+esc(t)+'</span></label>';
    });
    h+='</div>';
  });
  if(!e.topicsSet) h+='<div class="note warn"><b>Темы пока не выбраны — поэтому к вам приходят запросы по любым.</b> '+
    'Поставьте галочки только там, с чем работаете, и нажмите «Сохранить». Лучше меньше, но своих.</div>';
  h+='<div style="height:14px"></div><div style="padding-top:12px;border-top:1px solid #EFE7DB">'+
     '<label>С кем вы работаете</label><div class="chips">'+
     [['any','С женщинами и мужчинами'],['f','Только с женщинами'],['m','Только с мужчинами']].map(function(g){
       return '<button class="chip" aria-pressed="'+((e.genders||'any')===g[0])+'" data-a="eGender" data-v="'+g[0]+'">'+g[1]+'</button>';
     }).join('')+'</div>'+
     '<div class="field-hint">Клиент отвечает на этот вопрос при записи, и к вам попадут только те, с кем вы работаете. '+
     'На парные консультации приходят и мужчина, и женщина — их получают только те, кто выбрал «с женщинами и мужчинами».</div></div>'+
     '<label style="font-weight:400;display:flex;gap:9px;align-items:flex-start;padding-top:12px;border-top:1px solid #EFE7DB">'+
     '<input type="checkbox" id="ePairs" style="width:auto;margin:3px 0 0"'+(e.pairs?' checked':'')+'>'+
     '<span style="font-size:14px"><b>Беру парные консультации</b><br>'+
     '<span class="muted">Клиентка отвечает, придёт она одна или с партнёром. Если галочки нет, парные запросы к вам не попадут.</span></span></label>'+
     '<div style="height:12px"></div>'+
     '<button class="btn-ghost btn-sm" data-a="saveTopics">Сохранить</button></div>';

  h+='<div class="card"'+(!e.link?' style="border-color:var(--alarm)"':'')+'>'+
    '<div class="eyebrow">Профиль</div><h3>Ссылка на вашу комнату</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Встречи вы ведёте у себя — на своей площадке и со своим аккаунтом. '+
    'Дайте постоянную ссылку на комнату: Яндекс Телемост, Контур.Толк, Google Meet — что вам привычнее. '+
    'Её получает каждая записавшаяся, поэтому ссылка нужна <b>обязательно</b>.</p>'+
    '<input type="text" id="eLink" value="'+esc(e.link||'')+'" placeholder="https://telemost.yandex.ru/…">'+
    '<div class="field-hint">'+(e.link
      ? 'Поменяете ссылку — будущие встречи сами переедут на новую комнату, и клиенткам уйдёт свежая.'
      : '<b>Пока поле пустое, время открыть нельзя</b> и в записи вас нет.')+'</div>'+
    '<button class="btn'+(e.link?'-ghost btn-sm':'')+'" data-a="eSaveLink">Сохранить ссылку</button></div>';

  /* расписание: выпадающий календарь + время */
  var day=S.draftDate||todayISO();
  var taken={};
  slots.forEach(function(s){ if(String(s.dt).slice(0,10)===day) taken[String(s.dt).slice(11,16)]=1; });

  function timeOpts(sel,from,to){
    var o='';
    for(var m=8*60;m<=22*60;m+=15){
      if(from!=null&&m<from) continue;
      if(to!=null&&m>to) continue;
      var v=(Math.floor(m/60)<10?'0':'')+Math.floor(m/60)+':'+(m%60<10?'0':'')+(m%60);
      o+='<option'+(v===sel?' selected':'')+'>'+v+'</option>';
    }
    return o;
  }
  var from=S.tFrom||'10:00', to=S.tTo||'18:00', step=S.tStep||String(D.duration||50);

  h+='<div class="card"><div class="eyebrow">Ваш календарь</div><h3>Откройте время</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Выберите день в календаре, задайте промежуток — система разложит его на встречи по '+
    (D.duration||50)+' минут. Клиенты видят только время, без вашего имени.</p>'+
    (!e.link?'<div class="note warn" style="margin-bottom:14px"><b>Сначала ссылка на вашу комнату.</b> '+
      'Пока её нет, открывать время нечем: клиентке некуда прийти. Поле — в разделе «Профиль» выше.</div>':'')+
    '<label for="dDate">День</label><input type="date" id="dDate" value="'+esc(day)+'" min="'+todayISO()+'">'+
    '<div class="row">'+
      '<div><label for="tFrom">Начало</label><select id="tFrom">'+timeOpts(from)+'</select></div>'+
      '<div><label for="tTo">Конец</label><select id="tTo">'+timeOpts(to)+'</select></div>'+
      '<div><label for="tStep">Шаг</label><select id="tStep">'+
        ['30','45','50','60','90'].map(function(v){return '<option value="'+v+'"'+(v===step?' selected':'')+'>'+v+' мин</option>';}).join('')+
      '</select></div>'+
    '</div>'+
    '<label for="dRep">Повторить на несколько дней подряд</label>'+
    '<select id="dRep"><option value="1">только этот день</option><option value="3">3 дня подряд</option>'+
    '<option value="5">5 дней подряд</option><option value="7">неделю</option><option value="14">две недели</option></select>'+
    '<button class="btn" data-a="addRange"'+(!e.link?' disabled title="Сначала добавьте ссылку на свою комнату"':'')+'>Открыть время</button>'+
    '<div class="field-hint" style="margin-top:12px">Нужен один-единственный час — поставьте начало и конец рядом, например 15:00 и 15:00.</div>';

  var dayOpen=slots.filter(function(s){return String(s.dt).slice(0,10)===day;});
  h+='<div style="height:18px"></div><label>Что уже открыто у вас в этот день</label>';
  if(!dayOpen.length) h+='<div class="muted">Пока ничего.</div>';
  else{
    h+='<div class="chips">';
    dayOpen.forEach(function(s){
      h+='<button class="chip'+(s.booking_id?' taken':'')+'" '+(s.booking_id?'disabled title="уже занято клиентом"':'data-a="delSlot" data-id="'+s.id+'" title="убрать"')+'>'+
         fmtTime(s.dt)+(s.booking_id?' · занято':' ×')+'</button>';
    });
    h+='</div>';
  }
  h+='</div>';

  h+='<div class="card"><h3>Мои открытые слоты</h3><div style="height:12px"></div>';
  if(!freeSlots.length) h+='<div class="empty">Открытых слотов нет. Клиенты вас не увидят.</div>';
  else{
    h+='<div class="tickets">';
    freeSlots.forEach(function(s){
      h+='<button class="ticket" data-a="delSlot" data-id="'+s.id+'" title="Убрать слот">'+fmtTime(s.dt)+
         '<small>'+dt(s.dt).toLocaleDateString('ru-RU',{day:'numeric',month:'short'})+' · убрать</small></button>';
    });
    h+='</div>';
  }
  h+='</div>';

  h+='<div class="card"><h3>Ближайшие встречи</h3>'+
    '<p class="muted" style="margin:8px 0 14px"><b>Встреча начинается раньше, чем вы её открываете.</b> '+
    'Короткое сообщение накануне — и человек, который сомневался, приходит: он уже знаком с вами, и решиться легче. '+
    'Не отозвался — отметьте это здесь, дальше подключится координатор. '+
    'Молчаливое ожидание почти всегда заканчивается пустым часом.</p>';
  if(!upcoming.length) h+='<div class="empty">Записей пока нет.</div>';
  else{
    upcoming.forEach(function(b){
      var tel=(b.phone||'').replace(/\D/g,'');
      h+='<div style="border-top:1px solid #EFE7DB;padding-top:14px;margin-top:14px">'+
        '<div class="split"><div><b>'+esc(fmtFull(b.dt))+'</b> — '+esc(b.client_name)+
          (b.pair==1?' <span class="tag bad">пара</span>':'')+
          '<div class="muted" style="margin-top:4px">'+esc(b.category)+
          (b.note?' · «'+esc(b.note)+'»':'')+'</div></div>'+
        '<div>'+(b.confirm==='yes'?'<span class="tag ok">клиент подтвердил</span>'
                :b.confirm==='no'?'<span class="tag bad">не отвечает</span>'
                :'<span class="tag wait">не подтверждён</span>')+'</div></div>'+
        '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">'+
        (tel?'<a class="btn-ghost btn-sm" style="text-decoration:none" href="tel:+'+esc(tel)+'">Позвонить '+esc(b.phone)+'</a>':'')+
        (b.tg_nick?'<a class="btn-ghost btn-sm" style="text-decoration:none" href="https://t.me/'+esc(b.tg_nick)+'" target="_blank" rel="noopener">Написать @'+esc(b.tg_nick)+'</a>':'')+
        (!tel&&!b.tg_nick?'<span class="muted">Контакт: '+esc(b.contact)+'</span>':'')+
        (b.link?'<a class="btn-ghost btn-sm" style="text-decoration:none" href="'+esc(b.link)+'" target="_blank" rel="noopener">Ссылка на встречу</a>':'')+
        '</div>'+
        '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">'+
        (b.confirm!=='yes'?'<button class="btn btn-sm" data-a="confYes" data-id="'+b.id+'">Клиент подтвердил</button>':'')+
        (b.confirm!=='no'?'<button class="btn-ghost btn-sm" data-a="confNo" data-id="'+b.id+'">Не отвечает</button>':'')+
        (b.confirm?'<button class="btn-link" data-a="confClear" data-id="'+b.id+'" style="margin-left:6px">снять отметку</button>':'')+
        '<button class="btn-link" data-a="eCancel" data-id="'+b.id+'" style="margin-left:auto;color:var(--alarm)">Отменить встречу</button>'+
        '</div></div>';
    });
  }
  h+='</div>';

  h+='<div class="card"><h3>Прошедшие встречи</h3><p class="muted" style="margin:8px 0 14px">Отчёт сдаётся сразу после встречи. Пока отчёта нет, новых клиентов система отдаёт другим.</p>';
  if(!past.length) h+='<div class="empty">Пока пусто.</div>';
  past.forEach(function(b){
    h+='<div style="border-top:1px solid #EFE7DB;padding-top:16px;margin-top:16px">'+
       '<div class="split"><div><b>'+esc(fmtFull(b.dt))+'</b> — '+esc(b.client_name)+
       ' <span class="tag">'+esc(b.category)+'</span></div>'+statusTag(b)+'</div>';
    var editing=String(S.repEdit)===String(b.id);
    if(b.rep_at && !editing){
      h+='<div style="background:var(--sand);border-radius:3px;padding:12px;margin-top:10px">'+
        '<table style="margin:0">'+
        (b.rep_req ?'<tr><th style="width:150px">Запрос клиента</th><td>'+esc(b.rep_req)+'</td></tr>':'')+
        (b.rep_plan?'<tr><th style="width:150px">Гипотеза и план</th><td>'+esc(b.rep_plan)+'</td></tr>':'')+
        (b.rep_next?'<tr><th style="width:150px">Что дальше</th><td>'+esc(b.rep_next)+'</td></tr>':'')+
        '<tr><th style="width:150px">Сдан</th><td class="muted">'+esc(String(b.rep_at).slice(0,16).replace('T',' '))+
          (b.rep_upd?' · поправлен '+esc(String(b.rep_upd).slice(0,16).replace('T',' ')):'')+'</td></tr>'+
        '</table>'+
        '<div style="margin-top:10px"><button class="btn-ghost btn-sm" data-a="repEdit" data-id="'+b.id+'">Поправить отчёт</button></div>'+
        '</div>';
    }else{
      var v=function(x){ return editing?esc(x||''):''; };
      h+='<div style="margin-top:14px">'+
        (editing?'<div class="field-hint" style="margin-bottom:10px">Правите сданный отчёт. Прежний текст сохранится в истории записи — координатор увидит, что именно поменялось.</div>':'')+
        '<label>Встреча состоялась?</label><select data-f="status" data-id="'+b.id+'">'+
        '<option value="held"'+(b.status==='held'?' selected':'')+'>Да, провели</option>'+
        '<option value="noshow"'+(b.status==='noshow'?' selected':'')+'>Клиент не пришёл</option>'+
        '<option value="cancelled"'+(b.status==='cancelled'?' selected':'')+'>Отменили заранее</option></select>'+
        '<label>Запрос клиента</label><input type="text" data-f="req" data-id="'+b.id+'" value="'+v(b.rep_req)+'">'+
        '<label>Гипотеза и план</label><input type="text" data-f="plan" data-id="'+b.id+'" value="'+v(b.rep_plan)+'">'+
        '<label>Что предложили дальше</label><input type="text" data-f="next" data-id="'+b.id+'" value="'+v(b.rep_next)+'">'+
        '<button class="btn btn-sm" data-a="saveReport" data-id="'+b.id+'">'+(editing?'Сохранить правку':'Сдать отчёт')+'</button>'+
        (editing?' <button class="btn-link" data-a="repCancel">не менять</button>':'')+
        '</div>';
    }
    h+='</div>';
  });
  return h+'</div>';
}

/* ---------- Статистика: итоги, недели, источники ---------- */
function monthName(m){
  if(m==='all') return 'Всё время';
  var mm=['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
  var p=String(m).split('-');
  return (mm[parseInt(p[1],10)-1]||p[1])+' '+p[0];
}
function pct(a, b){ return b ? Math.round(a / b * 1000) / 10 + '%' : '—'; }

function aStats(){
  var D=S.stats;
  var m=S.statMonth||new Date().toISOString().slice(0,7);
  var now=new Date().toISOString().slice(0,7);
  var prevD=new Date(); prevD.setMonth(prevD.getMonth()-1);
  var prev=prevD.toISOString().slice(0,7);
  var months=(D&&D.months)||[m];
  var h='<div class="card"><div class="eyebrow">Период</div>'+
    '<h3>'+(m==='all'?'Статистика за всё время':'Статистика за '+esc(monthName(m).toLowerCase()))+'</h3>'+
    '<div class="chips" style="margin-top:12px">'+
    [['','Этот месяц',now],['','Прошлый месяц',prev],['','Всё время','all']].map(function(b){
      return '<button class="chip" aria-pressed="'+(m===b[2])+'" data-a="statPeriod" data-v="'+b[2]+'">'+b[1]+'</button>';
    }).join('')+'</div>'+
    '<label for="statMonth" style="margin-top:12px">Или выберите месяц</label>'+
    '<select id="statMonth" style="max-width:260px;margin:0">'+
      months.map(function(x){
        return '<option value="'+x+'"'+(m===x?' selected':'')+'>'+esc(monthName(x))+'</option>';
      }).join('')+
    '</select></div>';
  if(!D) return h+'<div class="card"><div class="empty">Загружаем…</div></div>';

  var t=D.sum, over=t.total-t.ahead, clear=t.done+t.noshow, real=t.done+t.await;
  h+='<div class="eyebrow">Записи</div><div class="metrics">'+
    metric(t.made,'Записалось клиенток')+
    metric(t.total,'Встреч назначено')+
    metric(t.ahead,'Ещё впереди')+
    '</div>'+
    '<div class="eyebrow" style="margin-top:18px">Чем закончились</div><div class="metrics">'+
    metric(t.done,'Проведено')+
    metric(t.noshow,'Не пришли')+
    metric(t.cancelled,'Отменено')+
    metric(t.await,'Без отчёта',t.await>0)+
    '</div>'+
    '<div class="eyebrow" style="margin-top:18px">Доходимость</div><div class="metrics">'+
    metric(pct(t.done,clear),'По отмеченным')+
    metric(pct(real,over),'Фактическая')+
    metric(pct(t.noshow,over),'Доля неявок')+
    metric(pct(t.cancelled,t.total),'Доля отмен')+
    metric(D.csat.avg||'—','Средняя оценка')+
    '</div>'+
    '<div class="field-hint" style="margin-top:10px">'+
    '<b>По отмеченным</b> — прошедшие против неявок, то есть насколько доходят те, чей исход известен. '+
    '<b>Фактическая</b> — состоявшиеся (включая встречи без отчёта) против всех прошедших, вместе с отменёнными. '+
    'Первая цифра всегда красивее, вторая честнее.</div>';

  h+='<div class="card"><h3>По неделям</h3><div class="scroll"><table>'+
    '<tr><th>Неделя</th><th style="text-align:right">Записалось</th><th style="text-align:right">Встреч</th>'+
    '<th style="text-align:right">Прошло</th><th style="text-align:right">Неявки</th>'+
    '<th style="text-align:right">Отмены</th><th style="text-align:right">Без отчёта</th>'+
    '<th style="text-align:right">Доходимость</th></tr>';
  if(!D.weeks.length) h+='<tr><td colspan="8"><div class="empty">За этот месяц данных нет.</div></td></tr>';
  D.weeks.forEach(function(w){
    var cl=w.done+w.noshow;
    h+='<tr><td><b>'+esc(w.from)+'–'+esc(w.to)+'</b></td>'+
      '<td style="text-align:right">'+w.made+'</td><td style="text-align:right">'+w.total+'</td>'+
      '<td style="text-align:right">'+w.done+'</td><td style="text-align:right">'+w.noshow+'</td>'+
      '<td style="text-align:right">'+w.cancelled+'</td><td style="text-align:right">'+w.await+'</td>'+
      '<td style="text-align:right">'+pct(w.done,cl)+'</td></tr>';
  });
  h+='</table></div></div>';

  var f=D.funnel;
  h+='<div class="card"><h3>Заходы и воронка</h3>';
  if(!D.visitsSince){
    h+='<div class="note">Счётчик заходов только что включён — данные начнут копиться со следующего посетителя. '+
       'Показывать конверсию «зашли → записались» он сможет через день-другой.</div>';
  }else{
    h+='<p class="muted" style="margin:8px 0 14px">Считаются открытия страницы записи, обезличенно: '+
       'день, метка источника и шаг. Данные с '+esc(D.visitsSince)+'.</p>'+
      '<div class="metrics">'+
      metric(f.open,'Открыли страницу')+
      metric(f.time,'Дошли до времени')+
      metric(f.form,'Дошли до формы')+
      metric(D.sum.made,'Записались')+
      metric(pct(D.sum.made,f.open),'Конверсия в запись')+
      '</div>'+
      '<div class="field-hint" style="margin-top:10px">Где обрывается путь: между «открыли» и «дошли до времени» '+
      'клиентка выбирает тему, формат и кто придёт. Если проседает здесь — дело в шагах, а не в расписании.</div>';
    if(D.bySource.length){
      h+='<div class="label" style="margin-top:14px">По источникам</div><div class="scroll"><table>'+
        '<tr><th>Метка</th><th style="text-align:right">Открыли</th><th style="text-align:right">До времени</th><th style="text-align:right">До формы</th></tr>';
      D.bySource.forEach(function(x){
        h+='<tr><td>'+esc(x.source)+'</td><td style="text-align:right">'+x.open+'</td>'+
          '<td style="text-align:right">'+x.time+'</td><td style="text-align:right">'+x.form+'</td></tr>';
      });
      h+='</table></div>';
    }
  }
  h+='</div>';

  h+='<div class="card"><h3>Источники записей</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Метка запоминается из ссылки, по которой пришла клиентка. '+
    '«Без метки» — переход по прямой ссылке без разметки.</p><div class="scroll"><table>'+
    '<tr><th>Источник</th><th style="text-align:right">Записей</th><th style="text-align:right">Доля</th></tr>';
  D.sources.forEach(function(x){
    h+='<tr><td>'+esc(x.s)+'</td><td style="text-align:right">'+x.c+'</td>'+
      '<td style="text-align:right">'+pct(x.c,D.sum.made)+'</td></tr>';
  });
  if(!D.sources.length) h+='<tr><td colspan="3"><div class="empty">Записей за месяц нет.</div></td></tr>';
  h+='</table></div></div>';
  return h;
}

/* ---------- Клиенты: люди, а не заявки ---------- */
function cliMatch(c, q){
  if(!q) return true;
  var digits=q.replace(/\D+/g,'');
  var hay=[c.name].concat(c.names||[], c.mail||[], (c.tg||[]).map(function(t){ return '@'+t; })).join(' ').toLowerCase();
  if(hay.indexOf(q)>=0) return true;
  if(digits.length>=3 && (c.phone||[]).join(' ').indexOf(digits)>=0) return true;
  return (c.bookings||[]).some(function(b){ return String(b.code||'').toLowerCase()===q; });
}
function cliPhone(p){ return p.length===10 ? '+7 '+p.slice(0,3)+' '+p.slice(3,6)+'-'+p.slice(6,8)+'-'+p.slice(8) : p; }

function aClients(){
  var all=S.clients;
  if(!all) return '<div class="card"><div class="empty">Загружаем базу…</div></div>';

  var q=(S.cliSearch||'').trim().toLowerCase();
  var found=all.filter(function(c){ return cliMatch(c,q); });
  var flt=S.cliFlt||'all';
  var pick=function(list){
    if(flt==='been')   return list.filter(function(c){ return c.done>0; });
    if(flt==='lost')   return list.filter(function(c){ return (c.cancelled+c.noshow)>0 && c.done===0 && c.ahead===0; });
    if(flt==='ahead')  return list.filter(function(c){ return c.ahead>0; });
    if(flt==='repeat') return list.filter(function(c){ return c.total>1; });
    return list;
  };
  var rows=pick(found);

  var h='<div class="metrics">'+
    metric(all.length,'Человек в базе')+
    metric(all.filter(function(c){ return c.done>0; }).length,'Были на встрече')+
    metric(all.filter(function(c){ return c.ahead>0; }).length,'Ждут встречи')+
    metric(all.filter(function(c){ return c.total>1; }).length,'Записывались не раз')+
    metric(all.filter(function(c){ return !c.phone.length && !c.tg.length && !c.mail.length; }).length,'Без контактов',
           all.filter(function(c){ return !c.phone.length && !c.tg.length && !c.mail.length; }).length>0)+
    '</div>';

  h+='<div class="card"><div class="split"><h3>База клиентов</h3>'+
    '<button class="btn-ghost btn-sm" data-a="cliCsv">Выгрузить CSV</button></div>'+
    '<p class="muted" style="margin:8px 0 14px">Все, кто когда-либо записывался. Одинаковый телефон, телеграм или почта '+
    'склеиваются в одного человека, даже если он записывался несколько раз и по-разному представлялся.</p>'+
    '<input type="text" id="cliSearch" placeholder="Поиск: имя, телефон, @телеграм, почта или код записи" '+
      'value="'+esc(S.cliSearch||'')+'" autocomplete="off">'+
    '<div class="chips" style="margin-top:12px">'+
    [['all','Все · '+found.length],['been','Были на встрече · '+found.filter(function(c){ return c.done>0; }).length],
     ['ahead','Ждут встречи · '+found.filter(function(c){ return c.ahead>0; }).length],
     ['repeat','Записывались не раз · '+found.filter(function(c){ return c.total>1; }).length],
     ['lost','Не дошли · '+found.filter(function(c){ return (c.cancelled+c.noshow)>0 && c.done===0 && c.ahead===0; }).length]]
     .map(function(f){
       return '<button class="chip" aria-pressed="'+(flt===f[0])+'" data-a="cliFlt" data-v="'+f[0]+'">'+f[1]+'</button>';
     }).join('')+'</div>';

  if(!rows.length) return h+'<div class="empty">'+(q?'По этому запросу никого нет.':'Здесь пока пусто.')+'</div></div>';

  rows.slice(0,300).forEach(function(c){
    var open=String(S.cliOpen)===String(c.id);
    var ch=[];
    c.phone.forEach(function(p){ ch.push('<a href="tel:+7'+esc(p)+'" style="text-decoration:none">'+esc(cliPhone(p))+'</a>'); });
    c.tg.forEach(function(t){ ch.push('<a href="https://t.me/'+esc(t)+'" target="_blank" rel="noopener">@'+esc(t)+'</a>'); });
    c.mail.forEach(function(m){ ch.push('<a href="mailto:'+esc(m)+'">'+esc(m)+'</a>'); });
    h+='<div style="border-top:1px solid #EFE7DB;padding-top:14px;margin-top:14px">'+
      '<div class="split"><div>'+
        '<b>'+esc(c.name)+'</b>'+
        (c.names.length>1?' <span class="muted">(также '+esc(c.names.slice(1).join(', '))+')</span>':'')+
        (c.total>1?' <span class="tag">записей: '+c.total+'</span>':'')+
        '<div class="muted" style="margin-top:3px">'+(ch.length?ch.join(' · '):'контактов нет')+'</div>'+
      '</div><div style="text-align:right">'+
        (c.done>0?'<span class="tag ok">была на '+c.done+'</span> ':'')+
        (c.ahead>0?'<span class="tag">впереди '+c.ahead+'</span> ':'')+
        (c.cancelled+c.noshow>0?'<span class="tag bad">не дошла '+(c.cancelled+c.noshow)+'</span> ':'')+
        (c.csat?'<span class="tag'+(Number(c.csat)<=3?' bad':' ok')+'">оценка '+esc(c.csat)+'</span>':'')+
      '</div></div>'+
      '<div style="margin-top:8px"><button class="btn-link" data-a="cliOpen" data-id="'+c.id+'">'+
        (open?'свернуть':'все записи и контакты')+'</button></div>';
    if(open){
      h+='<div style="background:var(--sand);border-radius:3px;padding:12px;margin-top:10px">'+
        '<table style="margin:0">'+
        (c.phone.length?'<tr><th style="width:130px">Телефон</th><td>'+c.phone.map(function(p){return esc(cliPhone(p));}).join('<br>')+'</td></tr>':'')+
        (c.tg.length?'<tr><th style="width:130px">Telegram</th><td>'+c.tg.map(function(t){return '@'+esc(t);}).join('<br>')+'</td></tr>':'')+
        (c.mail.length?'<tr><th style="width:130px">Почта</th><td>'+c.mail.map(esc).join('<br>')+'</td></tr>':'')+
        '<tr><th style="width:130px">Первая запись</th><td class="muted">'+esc(fmtFull(c.first))+'</td></tr>'+
        (c.source?'<tr><th>Источник</th><td>'+esc(c.source)+'</td></tr>':'')+
        '</table>'+
        '<div class="eyebrow" style="margin-top:14px">Записи</div><div class="scroll"><table style="margin:0">'+
        '<tr><th>Когда</th><th>Специалист</th><th>Запрос</th><th>Чем кончилось</th><th>Код</th></tr>'+
        c.bookings.map(function(b){
          return '<tr><td>'+esc(fmtFull(b.dt))+'</td><td>'+esc(b.expert||'—')+'</td>'+
            '<td>'+esc(b.category||'—')+(b.pair?' <span class="tag">пара</span>':'')+'</td>'+
            '<td>'+statusTag(b)+(b.csat?' <span class="tag'+(Number(b.csat)<=3?' bad':' ok')+'">'+esc(b.csat)+'/5</span>':'')+'</td>'+
            '<td><span class="code">'+esc(b.code)+'</span></td></tr>'+
            (b.comment?'<tr><td colspan="5" class="muted">«'+esc(b.comment)+'»</td></tr>':'');
        }).join('')+'</table></div></div>';
    }
    h+='</div>';
  });
  if(rows.length>300) h+='<div class="field-hint" style="margin-top:12px">Показаны первые 300 — уточните поиск.</div>';
  return h+'</div>';
}

/* ---------- 5. Координатор ---------- */
function vAdminLogin(){
  return '<div class="card"><div class="eyebrow">Координатор</div><h2>Вход</h2><div style="height:16px"></div>'+
    '<label for="aPin">Код доступа</label><input type="password" id="aPin" placeholder="код координатора">'+
    '<button class="btn" data-a="aLogin">Войти</button></div>';
}

function vAdmin(){
  var owner=(S.adm&&S.adm.role==='owner');
  var tabs=[['dash','Дашборд'],['book','Записи'],['cli','Клиенты'],['stat','Статистика'],['qa','Отчёты и оценки'],['exp','Специалисты'],['tg','Telegram'],['msg','Ручная рассылка']];
  if(owner) tabs.push(['set','Настройки']);
  if(!owner && S.adminTab==='set') S.adminTab='dash';
  var h='<div class="tabs" style="justify-content:space-between;align-items:center">'+
    '<div style="display:flex;gap:4px;flex-wrap:wrap">'+
      tabs.map(function(t){
        return '<button class="tab" aria-selected="'+(S.adminTab===t[0])+'" data-a="aTab" data-v="'+t[0]+'">'+t[1]+'</button>';
      }).join('')+
    '</div>'+
    '<div style="display:flex;align-items:center;gap:10px;padding-bottom:6px">'+
      '<span class="muted">вы вошли как '+(owner?'владелец':'координатор')+'</span>'+
      '<button class="btn-ghost btn-sm" data-a="aLogout">Выйти</button>'+
    '</div>'+
  '</div>';
  if(S.adminTab==='dash') h+=aDash();
  if(S.adminTab==='book') h+=aBookings();
  if(S.adminTab==='cli')  h+=aClients();
  if(S.adminTab==='stat') h+=aStats();
  if(S.adminTab==='qa')   h+=aQuality();
  if(S.adminTab==='exp')  h+=aExperts();
  if(S.adminTab==='tg')   h+=aTelegram();
  if(S.adminTab==='msg')  h+=aMessages();
  if(S.adminTab==='set')  h+=aSettings();
  return h;
}

function aDash(){
  var D=S.adm, ALL=D.bookings||[];
  var passed=passedOf(ALL);                    /* время прошло и не отменяли */
  var done=inState(ALL,'done'), noshow=inState(ALL,'noshow');
  var await_=inState(ALL,'await'), ahead=inState(ALL,'ahead'), cancelled=inState(ALL,'cancelled');
  var failed=failedOf(ALL);                    /* отмены и неявки — одной корзиной */
  var clear=done.length+failed.length;         /* записи с понятным исходом */
  var showRate=clear?Math.round(done.length/clear*100)+'%':'—';
  var csat=ALL.filter(function(b){ return b.csat; });
  var avg=avgOf(csat,function(b){ return b.csat; });
  var nps=avgOf(ALL,function(b){ return b.nps; });

  var h='<div class="eyebrow">Записи</div><div class="metrics">'+
    metric(ALL.length,'Записей всего')+
    metric(ahead.length,'Впереди')+
    metric(passed.length,'Время прошло')+
    metric(D.times.length,'Часов доступно клиенткам')+
    '</div>'+
    '<div class="eyebrow" style="margin-top:18px">Чем закончилось</div><div class="metrics">'+
    metric(done.length,'Реально прошли')+
    metric(failed.length,'Не прошли')+
    metric(await_.length,'Ждут отчёта',await_.length>0)+
    metric(showRate,'Доходимость',clear>0&&done.length/clear<0.75)+
    metric(csat.length+' / '+passed.length,'Оценок собрано')+
    metric(avg===null?'—':avg.toFixed(1),'Средний CSAT')+
    metric(nps===null?'—':Math.round(nps),'Средний NPS')+
    '</div>'+
    '<div class="field-hint" style="margin-top:10px">'+
      '<b>Реально прошли</b> — встречи, по которым специалист сдал отчёт: только они считаются состоявшимися. '+
      '<b>Не прошли</b> — '+cancelled.length+' отменённых и '+noshow.length+' неявок. '+
      '<b>Ждут отчёта</b> — '+await_.length+': час наступил, а специалист ещё не написал, чем кончилось, '+
      'поэтому в «прошли» они не попадают. <b>Доходимость</b> — доля прошедших среди записей с понятным исходом; '+
      'встречи без отчёта в неё не входят.</div>';

  var q=D.queue||{};
  if(!D.settings.tg_on){
    h+='<div class="note warn"><b>Telegram-бот не подключён.</b> Уведомления и опросы после встречи не уходят — только ручная рассылка. Вкладка «Telegram» — там три шага.</div>';
  }else if(q.pending>0){
    h+='<div class="note'+(q.pending>5?' warn':'')+'"><b>В очереди сообщений: '+q.pending+'.</b> Реле последний раз выходило на связь: '+
      esc(q.lastRelay||'ни разу')+'. Если очередь не тает — запустите реле на компьютере.</div>';
  }

  var T=D.team||null;
  if(T){
    h+='<div class="card"><div class="eyebrow">Команда</div><h3>Специалисты в системе</h3>'+
      '<div class="metrics" style="margin-top:12px">'+
      metric(T.total,'Всего специалистов')+
      metric(T.withSlots,'Открыли время',T.withSlots<T.total)+
      metric(T.withTg,'Подключили Telegram',T.withTg<T.total)+
      metric(T.withHeld,'Специалистов с встречами')+
      metric(T.held,'Встреч проведено')+
      '</div>'+
      '<div class="metrics">'+
      metric(T.rows.reduce(function(a,r){return a+r.load;},0),'Клиентов закреплено')+
      metric(T.rows.reduce(function(a,r){return a+r.left;},0),'Свободных мест по квотам',
             T.rows.reduce(function(a,r){return a+r.left;},0)===0)+
      metric(T.rows.reduce(function(a,r){return a+r.slots;},0),'Открытых слотов у команды',
             T.rows.reduce(function(a,r){return a+r.slots;},0)===0)+
      metric(T.rows.filter(function(r){return r.paused;}).length,'Показ закрыт')+
      '</div>';
    h+='<p class="muted" style="margin:0 0 12px">Неявки и отмены места в квоте не занимают — '+
      'после них время специалиста снова показывается клиенткам.</p>'+
      '<div class="field-hint" style="margin:0 0 14px"><b>Открытых слотов у команды</b> — сумма часов, '+
      'открытых всеми специалистами; один и тот же час у разных людей считается несколько раз. '+
      '<b>Часов доступно клиенткам</b> вверху — сколько из них клиентка реально видит в календаре: '+
      'совпавшие часы схлопываются в один, а занятые кабинетом и выбранные квоты не показываются. '+
      'Поэтому нижнее число всегда больше — это не расхождение.</div>';
    if(T.rows.length){
      h+='<div class="scroll"><table><tr><th>Специалист</th>'+
         '<th class="num">Записей</th><th class="num">Квота</th><th class="num">Осталось мест</th>'+
         '<th class="num">Свободных слотов</th><th class="num">Провела</th><th>TG</th></tr>';
      T.rows.forEach(function(r){
        var stop=r.paused||r.left===0||(r.slots===0);
        h+='<tr><td><b>'+esc(r.name)+'</b>'+
            (r.paused?' <span class="tag bad">слоты закрыты</span>':'')+
            (r.debt?' <span class="tag bad">долг '+r.debt+'</span>':'')+'</td>'+
          '<td class="num">'+r.load+'</td>'+
          '<td class="num">'+r.quota+'</td>'+
          '<td class="num"><b'+(r.left===0?' style="color:var(--alarm)"':'')+'>'+
            (r.left===0?'квота выбрана':r.left)+'</b></td>'+
          '<td class="num"'+(r.slots===0?' style="color:var(--alarm)"':'')+'>'+r.slots+'</td>'+
          '<td class="num">'+r.held+'</td>'+
          '<td>'+(r.tg?'<span class="tag ok">✓</span>':'<span class="tag bad">нет</span>')+'</td></tr>';
      });
      h+='</table></div>';
    }
    h+='<div class="field-hint" style="margin-top:10px">'+
       '<b>Записей</b> — сколько клиенток уже закреплено. <b>Осталось мест</b> — сколько ещё возьмёт до своей квоты; '+
       'ноль значит, что её время больше не показывается. <b>Свободных слотов</b> — сколько часов открыто и ещё не занято: '+
       'ноль здесь при живой квоте означает, что записывать некуда, нужно просить открыть время.</div></div>';
  }

  h+='<div class="card"><div class="split"><div><div class="eyebrow">Итоги дня</div>'+
    '<h3>Вечерняя сводка</h3></div>'+
    '<div style="white-space:nowrap"><button class="btn-ghost btn-sm" data-a="digestShow">Показать</button> '+
    '<button class="btn-ghost btn-sm" data-a="digestSend">Отправить сейчас</button></div></div>'+
    '<p class="muted" style="margin:8px 0 0">Каждый вечер в '+esc(D.settings.digest_at||'21:00')+
    ' всем подключённым к боту кураторам уходит короткий итог: новые записи, проведённые встречи, оценки, '+
    'кто открыл время и что требует внимания завтра.</p>'+
    (S.digest?'<div class="note mono" style="margin-top:12px;white-space:pre-line;font-family:inherit">'+esc(S.digest)+'</div>':'')+
    '</div>';

  var H=D.history||[];
  if(H.length){
    h+='<div class="card"><h3>История изменений</h3><p class="muted" style="margin:8px 0 12px">Замены специалистов, переносы и отмены — и кто их сделал.</p>'+
      '<div class="scroll"><table><tr><th>Когда</th><th>Кто</th><th>Что</th><th>Клиент</th><th>Код</th></tr>';
    H.slice(0,15).forEach(function(x){
      var kind={expert:'<span class="tag bad">замена</span>',move:'<span class="tag wait">перенос</span>',cancel:'<span class="tag">отмена</span>'}[x.kind]||'';
      h+='<tr><td class="muted">'+esc(String(x.at).slice(5,16))+'</td>'+
         '<td>'+(whoTag(x)||'<span class="muted">система</span>')+'</td>'+
         '<td>'+kind+' '+esc(x.txt)+'</td>'+
         '<td>'+esc(x.client_name||'—')+'</td><td>'+esc(x.code||'')+'</td></tr>';
    });
    h+='</table></div></div>';
  }

  h+='<div class="card"><h3>По специалистам</h3><p class="muted" style="margin:8px 0 14px">Распределение автоматическое: свободный слот получает та, у кого меньше клиентов. Квота — '+D.settings.quota+'.</p><div class="scroll"><table>'+
    '<tr><th>Специалист</th><th class="num">Слотов</th><th class="num">Записей</th><th class="num">Прошло</th><th class="num">Доходимость</th><th class="num">CSAT</th><th class="num">Отчётов</th><th style="padding-left:14px">TG</th></tr>';
  if(!D.experts.length) h+='<tr><td colspan="8"><div class="empty">Список пуст. Добавьте специалистов во вкладке «Специалисты».</div></td></tr>';
  D.experts.slice().sort(byPriority).forEach(function(e){
    var bb=D.bookings.filter(function(b){return b.expert_id==e.id && b.status!=='cancelled';});
    var pastB=bb.filter(function(b){return isPast(b.dt);});
    var hd=inState(bb,'done').length;
    var ns=inState(bb,'noshow').length;
    var cs=bb.filter(function(b){return b.csat;});
    var rep=pastB.filter(function(b){return b.rep_at;}).length;
    h+='<tr><td>'+prioDot(e)+' <b>'+esc(e.name)+'</b>'+(e.active?'':' <span class="tag">скрыт</span>')+'</td>'+
      '<td class="num">'+e.slots+'</td><td class="num">'+e.load+' / '+D.settings.quota+'</td><td class="num">'+hd+'</td>'+
      '<td class="num">'+((hd+ns)?Math.round(hd/(hd+ns)*100)+'%':'—')+'</td>'+
      '<td class="num">'+(cs.length?(cs.reduce(function(a,b){return a+Number(b.csat);},0)/cs.length).toFixed(1):'—')+'</td>'+
      '<td class="num">'+rep+' / '+pastB.length+(e.debt>0?' <span class="tag bad">долг '+e.debt+'</span>':'')+'</td>'+
      '<td style="padding-left:14px">'+(e.tgBound?'<span class="tag ok">есть</span>':'<span class="tag bad">нет</span>')+'</td></tr>';
  });
  h+='</table></div></div>';
  return h;
}

function aBookings(){
  var D=S.adm, B=D.bookings;
  var free=(D.freeSlots||[]);
  var expById={};
  D.experts.forEach(function(e){ expById[e.id]=e; });
  var allTopics=(S.boot&&S.boot.topics)||[];
  var tgroups=(S.boot&&S.boot.topicGroups)||[];

  /* Порядок как у клиентки: тема → формат → специалист → время */
  var h='<div class="card"><div class="eyebrow">Запись рукой</div><h3>Записать клиентку самой</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Для тех, кто написал в личку или пришёл списком. '+
    'Сначала тема и формат — потом покажем только тех, кто с этим работает, и только их свободные слоты.</p>';

  /* Нет свободных слотов — записывать некуда, но список записей нужен всегда:
     раньше он тут пропадал целиком, и казалось, что записи исчезли. */
  var noSlots = !free.length;
  if(noSlots){
    h+='<div class="note warn">Свободных слотов сейчас нет вообще — записывать некуда. '+
      'Попросите специалистов открыть время в кабинете, и слоты появятся здесь.</div></div>';
  }

  /* шаг 1 — тема */
  if(!noSlots){
  /* Список тот же, что у клиентки, и в том же порядке — иначе координатор ищет тему вслепую */
  var mList = tgroups.length
    ? tgroups.map(function(g){
        return '<optgroup label="'+esc(g.title||'Темы')+'">'+
          (g.items||[]).map(function(c){ return '<option'+(S.mTopic===c?' selected':'')+'>'+esc(c)+'</option>'; }).join('')+
          '</optgroup>';
      }).join('')
    : allTopics.map(function(c){ return '<option'+(S.mTopic===c?' selected':'')+'>'+esc(c)+'</option>'; }).join('');
  h+='<label for="mCat">Шаг 1. С чем идёт</label><select id="mCat">'+
     '<option value="">— выберите тему —</option>'+ mList +'</select>';

  if(!S.mTopic){
    h+='<div class="field-hint">Пока тема не выбрана, специалисты и слоты не показываются.</div></div>';
  }else{
    /* шаг 2 — формат */
    h+='<label>Шаг 2. Формат</label><div class="chips" style="margin-bottom:14px">'+
      '<button class="chip" aria-pressed="'+(!S.mPair)+'" data-a="mFmt" data-v="0">Индивидуальная</button>'+
      '<button class="chip" aria-pressed="'+(!!S.mPair)+'" data-a="mFmt" data-v="1">Парная</button>'+
      '</div>';

    /* кто подходит: тема + парность + есть свободные слоты */
    var fits=function(e){
      if(!e || !e.active) return false;
      if(S.mPair && !e.pairs) return false;
      var t=e.topics||[];
      return !t.length || t.indexOf(S.mTopic)>=0;
    };
    var ok=free.filter(function(f){ return fits(expById[f.expert_id]); });
    var byExpert={};
    ok.forEach(function(f){ (byExpert[f.expert_id]=byExpert[f.expert_id]||[]).push(f); });

    if(!ok.length){
      h+='<div class="note warn">По теме «'+esc(S.mTopic)+'»'+(S.mPair?' в парном формате':'')+
        ' свободных слотов нет. Попробуйте другой формат или тему, либо попросите нужного специалиста открыть время.'+
        (S.mPair?'<br>Проверьте также, отмечено ли у кого-нибудь «берёт парные» в разделе «Специалисты».':'')+'</div></div>';
    }else{
      var whoSel=S.mExpert||'';
      if(whoSel && !byExpert[whoSel]) whoSel='';
      var mine=whoSel?byExpert[whoSel]:ok;
      var seen={},times=[];
      mine.forEach(function(f){ if(!seen[f.dt]){seen[f.dt]=1;times.push(f);} });

      h+='<div class="row">'+
        '<div><label for="mExp">Шаг 3. Специалист</label><select id="mExp">'+
          '<option value="">подобрать автоматически</option>'+
          Object.keys(byExpert).map(function(id){
            var e=expById[id];
            return '<option value="'+id+'"'+(String(whoSel)===String(id)?' selected':'')+'>'+
              esc(e.name)+' — свободно '+byExpert[id].length+'</option>';
          }).join('')+'</select></div>'+
        '<div><label for="mDt">Шаг 4. Время</label><select id="mDt">'+
          times.map(function(f){
            return '<option value="'+esc(f.dt)+'"'+(S.mDt===f.dt?' selected':'')+'>'+esc(fmtFull(f.dt))+
              (whoSel?'':' — '+esc(f.ename))+'</option>';
          }).join('')+'</select></div></div>'+
        '<div class="row"><div><label for="mName">'+(S.mPair?'Имена клиентов':'Имя клиентки')+'</label>'+
          '<input type="text" id="mName" value="'+esc(S.mName||'')+'" placeholder="'+(S.mPair?'например: Сергей и Мария':'Как к ней обращаться')+'"></div></div>'+
        '<div class="row">'+
          '<div><label for="mPhone">Телефон</label>'+
            '<input type="text" id="mPhone" value="'+esc(S.mPhone||'')+'" inputmode="tel" placeholder="+7 999 123-45-67"></div>'+
          '<div><label for="mTg">Telegram — если есть</label>'+
            '<input type="text" id="mTg" value="'+esc(S.mTg||'')+'" placeholder="@nickname"></div>'+
        '</div>'+
        '<div class="field-hint" style="margin-top:-8px">Telegram не заполнен — клиентке уйдёт SMS сразу после записи, а дальше напоминания за сутки, '+
        'за 2 часа и за 15 минут. Заполнен — пришлём ей ссылку на бота, и всё пойдёт туда бесплатно. Специалисту уведомление уходит в любом случае.</div>'+
        '<label for="mNote">Заметка — по желанию</label><input type="text" id="mNote" maxlength="300">'+
        '<button class="btn" data-a="adminBook">Записать</button>'+
        '<div class="field-hint" style="margin-top:10px">Подходящих слотов: '+ok.length+
        (whoSel?' · у выбранного специалиста: '+mine.length:'')+'</div>';
    }
  }
  if(S.lastAdminBooking){
    var lb=S.lastAdminBooking;
    h+='<div class="note" style="margin-top:16px"><b>Записана:</b> '+esc(fmtFull(lb.dt))+' → '+esc(lb.expert)+
       '<br>Код <b>'+esc(lb.code)+'</b> · ссылка: '+(lb.link?'<a href="'+esc(lb.link)+'" target="_blank" rel="noopener">'+esc(lb.link)+'</a>':'—')+
       (lb.tg?'<br>Ссылка для клиентки, чтобы включить напоминания: <span class="mono">'+esc(lb.tg)+'</span> '+
       '<button class="btn-link" data-a="copy" data-txt="'+esc(lb.tg)+'">скопировать</button>':'')+'</div>';
  }
  h+='</div>';
  }   /* конец блока «записать рукой» — он есть только когда слоты открыты */

  /* ── Все записи: спокойный список, подробности по раскрытию ── */
  var q=(S.bookQ||'').trim().toLowerCase();
  var flt=S.bookFlt||'soon';
  var pass=function(b){
    if(q){
      var hay=(b.client_name+' '+(b.contact||'')+' '+(b.phone||'')+' '+(b.code||'')+' '+(b.ename||'')+' '+(b.category||'')).toLowerCase();
      if(hay.indexOf(q)<0) return false;
    }
    if(flt==='all') return true;
    if(flt==='soon') return b.status!=='cancelled' && !isPast(b.dt);
    if(flt==='today') return String(b.dt).slice(0,10)===todayISO();
    if(flt==='search') return b.status==='searching';
    if(flt==='past') return isPast(b.dt) && b.status!=='cancelled';
    if(flt==='cancelled') return b.status==='cancelled';
    return true;
  };
  var list=B.filter(pass);
  var cnt=function(f){ var was=flt; flt=f; var n=B.filter(pass).length; flt=was; return n; };

  h+='<div class="card"><div class="split"><h3>Все записи</h3>'+
    '<div style="white-space:nowrap">'+
    (D.role==='owner'?'<button class="btn-ghost btn-sm" data-a="trashShow">Корзина</button> ':'')+
    '<button class="btn-ghost btn-sm" data-a="csv">Выгрузить CSV</button></div></div>'+
    '<div style="height:12px"></div>'+
    '<div class="chips">'+
      [['soon','Предстоящие'],['today','Сегодня'],['search','Ждут специалиста'],
       ['past','Прошедшие'],['cancelled','Отменённые'],['all','Все']].map(function(f){
        var n=cnt(f[0]);
        return '<button class="chip" aria-pressed="'+(flt===f[0])+'" data-a="bookFlt" data-v="'+f[0]+'">'+
          f[1]+(n?' · '+n:'')+'</button>';
      }).join('')+
    '</div>'+
    '<input type="text" id="bookQ" value="'+esc(S.bookQ||'')+'" placeholder="Поиск по имени, телефону, коду или специалисту" autocomplete="off">';

  if(S.trash){
    h+='<div class="note" style="margin-top:12px"><b>Корзина — удалённые записи, хранятся месяц.</b>';
    if(!S.trash.length) h+='<div class="muted" style="margin-top:6px">Пусто.</div>';
    else{
      h+='<table style="margin-top:8px">';
      S.trash.forEach(function(t){
        h+='<tr><td class="muted" style="white-space:nowrap">'+esc(String(t.at).slice(5,16))+'</td>'+
          '<td><b>'+esc(t.name)+'</b> · '+esc(fmtFull(t.dt))+'<div class="muted">'+esc(t.expert)+' · '+esc(t.contact)+'</div></td>'+
          '<td style="text-align:right"><button class="btn-ghost btn-sm" data-a="trashRestore" data-id="'+t.id+'">Вернуть</button></td></tr>';
      });
      h+='</table>';
    }
    h+='<div style="margin-top:8px"><button class="btn-link" data-a="trashHide">закрыть</button></div></div>';
  }
  if(!list.length){
    h+='<div class="empty">Здесь пусто.</div></div>';
  }else{
    h+='<div class="scroll"><table>'+
      '<tr><th>Когда</th><th>Клиент</th><th>Специалист</th><th>Статус</th><th></th></tr>';
    list.forEach(function(b){
      var open=String(S.openBook)===String(b.id);
      h+='<tr><td style="white-space:nowrap"><b>'+esc(String(b.dt).slice(8,10)+'.'+String(b.dt).slice(5,7))+'</b> '+
          esc(fmtTime(b.dt))+'</td>'+
        '<td><b>'+esc(b.client_name)+'</b>'+(b.pair==1?' <span class="tag">пара</span>':'')+
          '<div class="muted">'+esc(b.category||'')+'</div></td>'+
        '<td>'+esc(b.ename||'—')+(b.changed?'<div><span class="tag bad">была замена</span></div>':'')+'</td>'+
        '<td>'+statusTag(b)+
          (b.status==='booked'&&!isPast(b.dt)
            ? '<div style="margin-top:3px">'+(b.confirm==='yes'?'<span class="tag ok">подтвердил</span>'
               :b.confirm==='no'?'<span class="tag bad">не отвечает</span>'
               :'<span class="tag wait">не подтверждён</span>')+'</div>' : '')+'</td>'+
        '<td style="text-align:right"><button class="btn-link" data-a="openBook" data-id="'+b.id+'">'+
          (open?'свернуть ▲':'подробнее ▼')+'</button></td></tr>';
      if(open) h+=bookDetails(b,D);
    });
    h+='</table></div></div>';
  }
  return h;
}

/* Подробности и действия по одной записи — раскрываются под строкой */
function bookDetails(b,D){
  var tel=(b.phone||'').replace(/\D/g,'');
  var kind={own:'комната специалиста',other:'старая ссылка',none:'нет'}[b.linkKind]||'';
  var x='<tr><td colspan="5" style="background:var(--sand);padding:14px 12px">';

  x+='<div class="two" style="display:flex;gap:22px;flex-wrap:wrap">';
  /* левая колонка — факты */
  x+='<div style="flex:1 1 260px">'+
      '<div class="eyebrow">Клиент</div>'+
      '<table style="margin:0">'+
      '<tr><th style="width:96px">Связь</th><td class="mono">'+esc(b.phone||b.contact||'—')+
        (b.tgNick?'<br>@'+esc(b.tgNick):'')+
        '<div style="margin-top:6px">'+
        (b.tg_chat?'<span class="tag ok">бот подключён</span>'
          :(tel?'<span class="tag wait">только SMS</span>':'<span class="tag bad">нет канала</span>'))+'</div></td></tr>'+
      (b.note?'<tr><th>Запрос</th><td>«'+esc(b.note)+'»</td></tr>':'')+
      '<tr><th>Встреча</th><td>'+(b.link?'<a href="'+esc(b.link)+'" target="_blank" rel="noopener">открыть</a> <span class="tag">'+esc(kind)+'</span>':'<span class="tag bad">ссылки нет</span>')+'</td></tr>'+
      '<tr><th>Код</th><td class="mono">'+esc(b.code)+'</td></tr>'+
      (D.settings.hook_on==='1'
        ? '<tr><th>В агрегатор</th><td>'+(b.hook==='sent'?'<span class="tag ok">передана</span>'
            :b.hook==='pending'?'<span class="tag bad">не приняли, повторяем</span>'
            :'<span class="tag">не отправлялась</span>')+'</td></tr>'
        : '')+
      (b.csat?'<tr><th>Оценка</th><td>'+esc(b.csat)+' из 5'+(b.nps!==null&&b.nps!==undefined?', NPS '+esc(b.nps):'')+
        (b.comment?'<div class="muted">«'+esc(b.comment)+'»</div>':'')+'</td></tr>':'')+
      '</table></div>';

  /* правая колонка — что можно сделать */
  x+='<div style="flex:1 1 260px">'+
      '<div class="eyebrow">Действия</div>'+
      '<div style="display:flex;flex-direction:column;gap:7px;align-items:flex-start">'+
      (tel?'<a class="btn-ghost btn-sm" style="text-decoration:none" href="tel:+'+esc(tel)+'">Позвонить</a>':'')+
      (!b.tg_chat&&tel&&D.settings.sms_on==='1'&&b.status!=='cancelled'
        ? '<button class="btn-ghost btn-sm" data-a="smsOne" data-id="'+b.id+'">Отправить SMS</button>':'')+
      (b.status==='searching'
        ? '<button class="btn btn-sm" data-a="swapStart" data-id="'+b.id+'">Назначить специалиста</button>'
        : (b.status!=='cancelled'
            ? '<button class="btn-ghost btn-sm" data-a="swapStart" data-id="'+b.id+'">Сменить специалиста</button>'+
              (!isPast(b.dt)?'<button class="btn-ghost btn-sm" data-a="unassign" data-id="'+b.id+'">Снять специалиста</button>':'')
            : ''))+
      (b.status!=='cancelled'&&!isPast(b.dt)
        ? '<button class="btn-ghost btn-sm" data-a="moveStartAdmin" data-id="'+b.id+'">Перенести на другое время</button>':'')+
      (b.status!=='cancelled'&&!isPast(b.dt)
        ? '<button class="btn-ghost btn-sm" data-a="adminCancel" data-id="'+b.id+'">Отменить встречу</button>':'')+
      '<button class="btn-ghost btn-sm" data-a="editStart" data-id="'+b.id+'">'+
        (String(S.editFor)===String(b.id)?'Закрыть правку':'Редактировать данные')+'</button>'+
      '<button class="btn-link" data-a="histFor" data-id="'+b.id+'">'+(String(S.histFor)===String(b.id)?'скрыть историю':'история')+'</button>'+
      (D.role==='owner'
        ? '<button class="btn-link" data-a="adminDelete" data-id="'+b.id+'" style="color:var(--alarm)">Удалить запись</button>'
        : '')+
      '</div></div>';
  x+='</div>';

  if(String(S.editFor)===String(b.id)){
    x+='<div style="margin-top:14px;border-top:1px solid var(--clay);padding-top:10px">'+
      '<div class="eyebrow">Данные клиента</div>'+
      '<p class="muted" style="margin:6px 0 12px">Время и специалист правятся отдельно — кнопками «Перенести» и «Сменить специалиста»: '+
      'там система проверяет занятость и предупреждает обе стороны.</p>'+
      '<div class="row"><div><label for="edName">Имя</label>'+
        '<input type="text" id="edName" value="'+esc(b.client_name||'')+'"></div>'+
        '<div><label for="edCat">Тема</label>'+
        '<input type="text" id="edCat" value="'+esc(b.category||'')+'"></div></div>'+
      '<div class="row"><div><label for="edPhone">Телефон</label>'+
        '<input type="text" id="edPhone" value="'+esc(b.phone||'')+'" inputmode="tel" placeholder="+7 999 123-45-67"></div>'+
        '<div><label for="edTg">Telegram</label>'+
        '<input type="text" id="edTg" value="'+esc(b.tgNick||'')+'" placeholder="@nickname"></div></div>'+
      '<div class="row"><div><label for="edMail">Почта</label>'+
        '<input type="text" id="edMail" value="'+esc(b.email||'')+'" placeholder="не обязательно"></div></div>'+
      '<label for="edNote">Запрос клиента</label>'+
      '<input type="text" id="edNote" maxlength="300" value="'+esc(b.note||'')+'">'+
      '<div class="field-hint" style="margin-top:-6px">SMS уходят строго на то, что вписано в «Телефон» — Telegram и почта на них не влияют. '+
      'Привязка к боту при смене ника не переезжает: клиент подключает бота заново по своей ссылке.</div>'+
      '<button class="btn btn-sm" data-a="editSave" data-id="'+b.id+'">Сохранить</button> '+
      '<button class="btn-link" data-a="editCancel" style="margin-left:10px">отмена</button>'+
      '</div>';
  }

  if(String(S.swapFor)===String(b.id)){
    var searching=(b.status==='searching');
    var cands=D.experts.filter(function(e){
      if(!e.active || String(e.id)===String(b.expert_id)) return false;
      if(b.pair==1 && !e.pairs) return false;
      return true;
    });
    x+='<div style="margin-top:14px;border-top:1px solid var(--clay);padding-top:10px">'+
      '<div class="eyebrow">'+(searching?'Назначение специалиста':'Замена специалиста')+'</div>'+
      '<p class="muted" style="margin:6px 0 10px">'+
      (searching
        ? 'Клиентка ждёт: запись висит без специалиста и она об этом не знает. Как только выберете — ей уйдёт подтверждение со ссылкой.'
        : 'Время, клиент и ссылка останутся прежними — поменяется только тот, кто проводит. Обеим сторонам уйдёт уведомление.')+
      (b.pair==1?'<br><b>Встреча парная</b> — в списке только те, кто берёт пары.':'')+'</p>';
    if(!cands.length){
      x+='<div class="note warn">Некого предложить: '+
        (b.pair==1?'ни у кого не отмечено «берёт парные консультации».':'в списке нет других специалистов.')+'</div>';
    }else{
      x+='<label for="swExp">Кто проведёт</label><select id="swExp">'+
        cands.slice().sort(byPriority).map(function(e){
          return '<option value="'+e.id+'">'+PRIO[prioOf(e)-1].name.charAt(0).toUpperCase()+' · '+esc(e.name)+' — '+e.load+' из '+e.quota+
            (e.direction?' · '+esc(e.direction):'')+(e.paused?' · показ закрыт':'')+'</option>';
        }).join('')+'</select>'+
        '<label for="swWhy">Причина — по желанию, попадёт в историю</label>'+
        '<input type="text" id="swWhy" placeholder="например: заболела, накладка по времени">'+
        '<button class="btn btn-sm" data-a="'+(searching?'assignDo':'swapDo')+'" data-id="'+b.id+'">'+
          (searching?'Назначить и сообщить клиентке':'Заменить')+'</button> '+
        '<button class="btn-link" data-a="swapCancel" style="margin-left:10px">отмена</button>';
    }
    x+='</div>';
  }
  if(String(S.moveAdmin)===String(b.id)){
    var free=(D.freeSlots||[]);
    var same=free.filter(function(f){ return String(f.expert_id)===String(b.expert_id); });
    var other=[], seen={};
    free.forEach(function(f){
      if(String(f.expert_id)===String(b.expert_id)) return;
      if(seen[f.dt]) return; seen[f.dt]=1; other.push(f);
    });
    x+='<div style="margin-top:14px;border-top:1px solid var(--clay);padding-top:10px">'+
      '<div class="eyebrow">Перенос</div>';
    if(!same.length && !other.length) x+='<div class="muted">Свободных слотов нет — переносить некуда.</div>';
    else{
      x+='<label for="mvDt">Новое время</label><select id="mvDt">'+
        (same.length?'<optgroup label="У того же специалиста">'+
          same.map(function(f){return '<option value="'+esc(f.dt)+'">'+esc(fmtFull(f.dt))+'</option>';}).join('')+'</optgroup>':'')+
        (other.length?'<optgroup label="Другое время — специалист может смениться">'+
          other.map(function(f){return '<option value="'+esc(f.dt)+'">'+esc(fmtFull(f.dt))+' — '+esc(f.ename)+'</option>';}).join('')+'</optgroup>':'')+
        '</select>'+
        '<div class="field-hint">Если специалист свободен в новое время — останется он же и ссылка не изменится. '+
        'Клиентке и специалисту уйдёт уведомление о переносе.</div>'+
        '<button class="btn btn-sm" data-a="moveDoAdmin" data-id="'+b.id+'">Перенести</button> '+
        '<button class="btn-link" data-a="moveCancelAdmin" style="margin-left:10px">отмена</button>';
    }
    x+='</div>';
  }
  if(String(S.histFor)===String(b.id)){
    var L=S.histLog;
    x+='<div style="margin-top:14px;border-top:1px solid var(--clay);padding-top:10px">'+
      '<div class="eyebrow">Что происходило</div>';
    if(!L) x+='<div class="muted">Загружаю…</div>';
    else if(!L.length) x+='<div class="muted">Пока ничего.</div>';
    else{
      x+='<table style="margin:0">';
      L.forEach(function(e){
        x+='<tr><td style="width:110px;white-space:nowrap" class="muted">'+esc(String(e.at).slice(5,16))+'</td>'+
          '<td>'+whoTag(e)+esc(e.what)+(e.how?' <span class="muted">· '+esc(e.how)+'</span>':'')+'</td></tr>';
      });
      x+='</table>';
    }
    x+='</div>';
  }
  return x+'</td></tr>';
}

/* Кто сделал изменение: клиент сам, специалист, куратор или автоматика */
function whoTag(e){
  var w=e.who||'system';
  if(w==='system') return '';
  var cls={client:'tag wait',expert:'tag ok',curator:'tag',owner:'tag'}[w]||'tag';
  var txt=e.whoLabel||w;
  if(e.via) txt+=' · '+e.via;
  return '<span class="'+cls+'" style="margin-right:8px">'+esc(txt)+'</span>';
}

/* Разворачивающаяся история по записи */
function histRow(b){
  var L=S.histLog;
  var inner;
  if(!L) inner='<div class="muted">Загружаю…</div>';
  else if(!L.length) inner='<div class="muted">Пока ничего не происходило.</div>';
  else{
    inner='<table style="margin:0">';
    L.forEach(function(x){
      inner+='<tr><td style="width:120px;white-space:nowrap" class="muted">'+esc(String(x.at).slice(5,16))+'</td>'+
        '<td>'+whoTag(x)+'<b>'+esc(x.what)+'</b>'+(x.how?' <span class="muted">· '+esc(x.how)+'</span>':'')+'</td></tr>';
    });
    inner+='</table>';
  }
  return '<tr><td colspan="12" style="background:var(--sand);padding:12px 10px">'+
    '<div class="eyebrow" style="margin-bottom:8px">История · '+esc(b.client_name)+'</div>'+inner+'</td></tr>';
}

/* Отчётность: что специалисты написали после встреч и как их оценили клиентки */
function aQuality(){
  var D=S.adm, B=D.bookings||[];
  var passed=passedOf(B);                       /* тот же счёт, что и в дашборде */
  var tab=S.qaTab||'rep';
  var h='<div class="tabs" style="margin-bottom:14px">'+
    [['rep','Отчёты специалистов'],['csat','Оценки клиенток']].map(function(t){
      return '<button class="tab" aria-selected="'+(tab===t[0])+'" data-a="qaTab" data-v="'+t[0]+'">'+t[1]+'</button>';
    }).join('')+'</div>';
  return h + (tab==='rep' ? qaReports(passed, B) : qaRatings(B, passed));
}

/* ── Отчёты: что специалист написал после встречи ── */
function qaReports(passed, all){
  var done=inState(passed,'done');                                  /* отчёт сдан, встреча состоялась */
  var wait=inState(passed,'await');                                 /* отчёта нет — исход неизвестен */
  var fixed=done.filter(function(b){ return b.rep_upd; });
  var failed=failedOf(all||passed);                                 /* отмены и неявки */
  var noshow=inState(passed,'noshow');

  var h='<div class="metrics">'+
    metric(done.length,'Реально прошли')+
    metric(wait.length,'Ждём отчёта',wait.length>0)+
    metric(failed.length,'Не прошли')+
    metric(fixed.length,'Отчётов поправлено')+
    metric(passed.length,'Время прошло')+
    '</div>'+
    '<div class="field-hint" style="margin:10px 0 0">Состоявшейся считается встреча, по которой специалист сдал отчёт. '+
    'Пока отчёта нет, встреча висит в «ждём отчёта» — даже если час давно прошёл. '+
    'В «не прошли» — '+(failed.length-noshow.length)+' отменённых и '+noshow.length+' неявок.</div>';

  var flt=S.qaFlt||'all';
  var rows = flt==='norep'?wait : flt==='done'?done : flt==='fixed'?fixed : passed.slice();
  rows=rows.slice().sort(function(a,b){ return a.dt<b.dt?1:-1; });

  h+='<div class="card"><div class="split"><h3>Что написали специалисты</h3>'+
    '<button class="btn-ghost btn-sm" data-a="csv">Выгрузить CSV</button></div>'+
    '<p class="muted" style="margin:8px 0 0">Отчёт сдаётся после встречи и может быть поправлен позже — '+
    'у поправленных стоит метка, прежний текст виден в истории записи.</p>'+
    '<div style="height:12px"></div><div class="chips">'+
    [['all','Время прошло · '+passed.length],['norep','Ждём отчёта · '+wait.length],
     ['done','Реально прошли · '+done.length],['fixed','Поправлен · '+fixed.length]].map(function(f){
      return '<button class="chip" aria-pressed="'+(flt===f[0])+'" data-a="qaFlt" data-v="'+f[0]+'">'+f[1]+'</button>';
    }).join('')+'</div>';
  if(!rows.length) return h+'<div class="empty">Здесь пусто.</div></div>';

  rows.forEach(function(b){
    var open=String(S.qaOpen)===String(b.id);
    h+=qaHead(b, bkState(b)==='noshow'?'<span class="tag bad">не пришёл</span>'
      :b.rep_at?'<span class="tag ok">прошла, отчёт сдан</span>'+(b.rep_upd?' <span class="tag">поправлен</span>':'')
               :'<span class="tag wait">ждём отчёта</span>');
    if(b.rep_at){
      h+='<div style="margin-top:8px"><button class="btn-link" data-a="qaOpen" data-id="'+b.id+'">'+
         (open?'свернуть':'посмотреть отчёт')+'</button></div>';
      if(open) h+='<div style="background:var(--sand);border-radius:3px;padding:12px;margin-top:10px">'+qaRepBody(b)+'</div>';
    }else if(bkState(b)!=='noshow'){
      h+='<div class="muted" style="margin-top:6px">Специалист ещё не написал, что было на встрече. '+
         'Напоминание уходит через три часа после конца, дальше — раз в сутки его слоты уступают другим.</div>';
    }
    h+='</div>';
  });
  return h+'</div>';
}

/* ── Оценки: что ответила клиентка ── */
function qaRatings(B, passed){
  var rated=passed.filter(function(b){ return b.csat; });
  var orphan=rated.filter(function(b){ return !b.rep_at; });   /* клиентка ответила, а специалист молчит */
  var noRate=passed.filter(function(b){ return !b.csat && bkState(b)!=='noshow'; });
  var low=rated.filter(function(b){ return Number(b.csat)<=3; });
  var comments=rated.filter(function(b){ return (b.comment||'').trim(); });
  var avg=avgOf(rated,function(b){ return b.csat; });
  var nps=avgOf(B,function(b){ return b.nps; });

  var h='<div class="metrics">'+
    metric(rated.length+' / '+passed.length,'Оценок собрано')+
    metric(avg===null?'—':avg.toFixed(1),'Средний CSAT')+
    metric(nps===null?'—':Math.round(nps),'Средний NPS')+
    metric(low.length,'Оценок ниже 4',low.length>0)+
    metric(comments.length,'С комментарием')+
    metric(orphan.length,'Оценка есть, отчёта нет',orphan.length>0)+
    '</div>';

  var flt=S.qaFlt2||'rated';
  var rows = flt==='low'?low : flt==='texts'?comments : flt==='norate'?noRate : rated.slice();
  rows=rows.slice().sort(function(a,b){ return a.dt<b.dt?1:-1; });

  h+='<div class="card"><div class="split"><h3>Что ответили клиентки</h3>'+
    '<button class="btn-ghost btn-sm" data-a="csv">Выгрузить CSV</button></div>'+
    '<p class="muted" style="margin:8px 0 0">Опрос уходит клиентке после встречи: оценка от 1 до 5, подошёл ли специалист '+
    'и готовность порекомендовать. Ответ добровольный — часть встреч останется без оценки. Знаменатель — '+
    'реально прошедшие встречи; если оценок больше, значит клиентка ответила по встрече, отчёта по которой ещё нет.</p>'+
    '<div style="height:12px"></div><div class="chips">'+
    [['rated','С оценкой · '+rated.length],['low','Ниже 4 · '+low.length],
     ['texts','С комментарием · '+comments.length],['norate','Без оценки · '+noRate.length]].map(function(f){
      return '<button class="chip" aria-pressed="'+(flt===f[0])+'" data-a="qaFlt2" data-v="'+f[0]+'">'+f[1]+'</button>';
    }).join('')+'</div>';
  if(!rows.length) return h+'<div class="empty">Здесь пусто.</div></div>';

  rows.forEach(function(b){
    var open=String(S.qaOpen)===String(b.id);
    h+=qaHead(b, b.csat?'<span class="tag'+(Number(b.csat)<=3?' bad':' ok')+'">оценка '+esc(b.csat)+'/5</span>'
                       :'<span class="tag">оценки нет</span>');
    if(b.csat){
      h+='<div style="margin-top:8px"><button class="btn-link" data-a="qaOpen" data-id="'+b.id+'">'+
         (open?'свернуть':'посмотреть ответ')+'</button></div>';
      if(open) h+='<div style="background:var(--sand);border-radius:3px;padding:12px;margin-top:10px">'+qaCsatBody(b)+
        (b.rep_at?'<div style="margin-top:12px">'+qaRepBody(b)+'</div>':'')+'</div>';
    }
    h+='</div>';
  });
  return h+'</div>';
}

function qaHead(b, tag){
  return '<div style="border-top:1px solid #EFE7DB;padding-top:14px;margin-top:14px">'+
    '<div class="split"><div>'+
      '<b>'+esc(fmtFull(b.dt))+'</b> — '+esc(b.client_name)+
      (b.pair==1?' <span class="tag">пара</span>':'')+
      '<div class="muted" style="margin-top:3px">'+esc(b.ename||'—')+' · '+esc(b.category||'')+'</div>'+
    '</div><div style="text-align:right">'+tag+'</div></div>';
}
function qaRepBody(b){
  return '<div class="eyebrow">Отчёт специалиста</div><table style="margin:0">'+
    (b.rep_req ?'<tr><th style="width:150px">Запрос клиента</th><td>'+esc(b.rep_req)+'</td></tr>':'')+
    (b.rep_plan?'<tr><th style="width:150px">Гипотеза и план</th><td>'+esc(b.rep_plan)+'</td></tr>':'')+
    (b.rep_next?'<tr><th style="width:150px">Что дальше</th><td>'+esc(b.rep_next)+'</td></tr>':'')+
    '<tr><th style="width:150px">Сдан</th><td class="muted">'+esc(String(b.rep_at).slice(0,16))+
      (b.rep_upd?' · поправлен '+esc(String(b.rep_upd).slice(0,16)):'')+'</td></tr></table>';
}
function qaCsatBody(b){
  return '<div class="eyebrow">Оценка клиентки</div><table style="margin:0">'+
    '<tr><th style="width:150px">Встреча</th><td>'+esc(b.csat)+' из 5</td></tr>'+
    (b.fit?'<tr><th>Специалист подошёл</th><td>'+esc(b.fit)+'</td></tr>':'')+
    (b.nps!==null&&b.nps!==undefined&&b.nps!==''?'<tr><th>Порекомендует</th><td>'+esc(b.nps)+' из 10</td></tr>':'')+
    (b.comment?'<tr><th>Своими словами</th><td>«'+esc(b.comment)+'»</td></tr>':'')+
    '</table>';
}

var PRIO=[{v:1,dot:'#22c55e',name:'зелёный',hint:'клиентов отдаём в первую очередь'},
          {v:2,dot:'#eab308',name:'жёлтый', hint:'обычная очередь'},
          {v:3,dot:'#ef4444',name:'красный',hint:'клиентов отдаём в последнюю очередь'}];
function prioOf(e){ var p=Number(e.priority||2); return (p>=1&&p<=3)?p:2; }
function prioDot(e){
  var p=PRIO[prioOf(e)-1];
  return '<span title="Приоритет: '+p.name+' — '+p.hint+'" style="display:inline-block;width:9px;height:9px;'+
         'border-radius:50%;background:'+p.dot+';vertical-align:middle"></span>';
}
function prioPicker(e){
  var cur=prioOf(e);
  return '<span style="display:inline-flex;gap:4px">'+PRIO.map(function(p){
    return '<button data-a="prio" data-id="'+e.id+'" data-v="'+p.v+'" title="'+p.name+' — '+p.hint+'" '+
      'style="width:22px;height:22px;padding:0;margin:0;border-radius:50%;cursor:pointer;background:'+p.dot+';'+
      'border:2px solid '+(cur===p.v?'#111':'transparent')+';opacity:'+(cur===p.v?'1':'.35')+'"></button>';
  }).join('')+'</span>';
}
/* зелёные первыми, внутри цвета — кто меньше загружен */
function byPriority(a,b){ return (prioOf(a)-prioOf(b)) || (Number(a.load||0)-Number(b.load||0)); }

function aExperts(){
  var D=S.adm, s=D.settings;
  var qq=(S.expSearch||'').trim().toLowerCase();
  var list=qq?D.experts.filter(function(e){return e.name.toLowerCase().indexOf(qq)>=0
    || (e.direction||'').toLowerCase().indexOf(qq)>=0;}):D.experts;

  var owner=(D.role==='owner');
  var h='';
  h+='<div class="card"><h3>Специалистов в списке: '+D.experts.length+'</h3>'+
    '<p class="muted" style="margin:8px 0 14px">'+
    (owner
      ? 'Состав ведёте вы: специалисты добавляются вручную формой внизу. Кого зовём работать — решаете тоже вы: '+
        'ссылку на кабинет отправляете только выбранным. «Скрыть» — для тех, кто ушёл: они пропадут из списка на входе.'
      : 'Специалистов можно добавлять вручную формой внизу, а имя, приоритет и квоту править прямо в таблице — '+
        'ждать владельца для этого не нужно.')+'</p>'+
    '<input type="text" id="expSearch" placeholder="Поиск по фамилии или направлению" value="'+esc(S.expSearch||'')+'" autocomplete="off">';
  if(!D.experts.length) h+='<div class="empty">Список пуст. Нажмите «Обновить список» выше.</div>';
  else{
    h+='<div class="scroll"><table><tr>'+(owner?'<th></th>':'')+'<th>Имя</th><th>Направление</th><th>Парные</th>'+
       '<th>Приоритет</th><th>Комната</th><th class="num">Слотов</th><th class="num">Клиентов</th><th>Telegram</th></tr>';
    list.forEach(function(e){
      h+='<tr>'+(owner?'<td style="white-space:nowrap">'+
          '<button class="btn-link" data-a="pauseExpert" data-id="'+e.id+'">'+(e.paused?'открыть показ':'закрыть показ')+'</button>'+
          '<br><button class="btn-link" data-a="toggleExpert" data-id="'+e.id+'">'+(e.active?'скрыть':'вернуть')+'</button>'+
          '<br><button class="btn-link" data-a="toggleTest" data-id="'+e.id+'">'+(e.test?'сделать боевой':'сделать тестовой')+'</button></td>':'')+
        '<td>'+(String(S.renameFor)===String(e.id)
            ? '<input type="text" id="renameBox" value="'+esc(e.name)+'" style="margin:0 0 4px;padding:4px 6px;font-size:13px">'+
              '<button class="btn-link" data-a="renameDo" data-id="'+e.id+'">сохранить</button> '+
              '<button class="btn-link" data-a="renameCancel">отмена</button>'
            : '<b>'+esc(e.name)+'</b> <button class="btn-link" data-a="renameStart" data-id="'+e.id+'" '+
              'title="поправить опечатку в имени">✎</button>')+
          (e.test?' <span class="tag">тестовая</span>':'')+(e.active?'':' <span class="tag">скрыта</span>')+
          (D.settings.rules_gate==='1'&&!e.rulesAt?' <span class="tag bad">не принял правила</span>':'')+
          (e.paused?' <span class="tag bad">слоты закрыты</span>':'')+
          (e.load>=e.quota?' <span class="tag wait">квота выбрана</span>':'')+'</td>'+
        '<td>'+esc(e.direction||'—')+'</td>'+
        '<td>'+(owner
          ? '<button class="btn-link" data-a="togglePairs" data-id="'+e.id+'">'+
            (e.pairs?'<span class="tag ok">берёт</span>':'<span class="tag">нет</span>')+'</button>'
          : (e.pairs?'<span class="tag ok">берёт</span>':'<span class="tag">нет</span>'))+'</td>'+
        '<td class="num" style="white-space:nowrap">'+
          '<input type="number" min="0" max="200" value="'+e.quota+'" data-q="'+e.id+'" '+
            'style="width:56px;padding:4px 6px;margin:0;text-align:right;font-size:13px">'+
          '<div class="muted" style="font-size:11px">'+(e.ownQuota?'своя':'общая')+'</div></td>'+
        '<td style="white-space:nowrap">'+prioPicker(e)+'</td>'+
        '<td>'+(e.link?'<span class="tag ok">своя</span>':'<span class="tag">общая</span>')+'</td>'+
        '<td class="num">'+e.slots+'</td>'+
        '<td class="num">'+e.load+' / '+e.quota+'</td>'+
        '<td>'+(e.tgBound?'<span class="tag ok">подключён</span>':
          (e.tgLink?'<button class="btn-link" data-a="copy" data-txt="'+esc(e.tgLink)+'">ссылка</button>':'<span class="tag bad">бот не настроен</span>'))+
          (e.tgUser?' <span class="muted">@'+esc(e.tgUser)+'</span>':'')+'</td></tr>';
    });
    h+='</table></div>';
    h+='<div class="note" style="margin-top:16px">Каждой выбранной отправьте две ссылки: общую на кабинет — там она выбирает себя из списка — '+
       'и персональную для подключения Telegram из этой таблицы. Вторая личная: по ней бот узнаёт, кому слать уведомления.</div>';
  }
  h+='</div>';

  /* Добавить человека руками может и куратор: кто-то приходит в поток вне общей таблицы */
  h+='<div class="card"><div class="eyebrow">Вручную</div><h3>Добавить специалиста</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Для тех, кого нет в таблице школы. Кода доступа не нужно: '+
    'она войдёт в кабинет по общей ссылке, выбрав себя из списка. Темы, парные и ссылку на комнату '+
    'она отметит у себя сама — или вы поставите здесь же, в таблице выше.</p>'+
    '<div class="row"><div><label for="nName">Имя и фамилия</label><input type="text" id="nName" placeholder="Мария Иванова"></div>'+
    '<div><label for="nDir">Направление</label><select id="nDir"><option value="">не указано</option>'+
      ((S.boot&&S.boot.directions)||[]).map(function(d){
        return '<option value="'+esc(d)+'">'+esc(d)+'</option>'; }).join('')+
    '</select></div></div>'+
    '<label for="nLink">Ссылка на постоянную комнату — по желанию</label><input type="text" id="nLink" placeholder="https://telemost.yandex.ru/…">'+
    '<button class="btn" data-a="addExpert">Добавить в список</button></div>';
  return h;
}

function aTelegram(){
  var D=S.adm, s=D.settings, q=D.queue||{};
  var origin=location.origin+location.pathname.replace(/index\.html$/,'');

  if(!TECH){
    var h='<div class="card"><div class="eyebrow">Уведомления</div><h3>Бот и рассылка</h3>'+
      '<div style="height:12px"></div>'+
      '<table><tr><th style="width:220px">Бот</th><td>'+(s.tg_on?'<span class="tag ok">подключён</span> @'+esc(s.bot_user||''):'<span class="tag bad">не подключён</span>')+'</td></tr>'+
      '<tr><th>Сообщений ждёт отправки</th><td><b>'+(q.pending||0)+'</b></td></tr>'+
      '<tr><th>Доставка работала</th><td>'+esc(q.lastRelay||'ни разу')+'</td></tr>'+
      '<tr><th>Планировщик</th><td>'+esc(q.lastTick||'—')+'</td></tr></table>';
    if((q.pending||0)>3) h+='<div class="note warn" style="margin-top:14px">Очередь не тает — похоже, программа доставки на компьютере не запущена. '+
      'Пока она не поднимется, рассылайте напоминания вручную из соседней вкладки.</div>';
    h+='<div class="field-hint" style="margin-top:14px">Ключи и настройки подключения спрятаны, чтобы их случайно не сломать.</div></div>';
    if(s.bot_user) h+=curatorCard(D);
    h+=tgPeopleCard();
    return h;
  }
  var h='<div class="card"><div class="eyebrow">Автоматизация</div><h3>Telegram-бот: три шага</h3>'+
    '<p class="muted" style="margin:8px 0 0">Наш сервер не может ходить в Telegram напрямую — хостинг его блокирует. Поэтому сообщения копятся в очереди, '+
    'а маленькая программа-реле на вашем компьютере забирает их и доставляет. Логика и данные — на сервере, реле только транспорт.</p></div>';

  h+='<div class="card"><h3>Шаг 1. Токен бота</h3><div style="height:12px"></div>'+
    '<p class="muted">В Telegram напишите @BotFather → /newbot → имя и адрес бота. Он выдаст токен вида <span class="mono">1234567:AA…</span> — вставьте сюда.</p>'+
    '<label for="tgTok">Токен</label><input type="text" id="tgTok" placeholder="'+(s.tg_on?'токен сохранён — вставьте новый, чтобы заменить':'123456:AA…')+'">'+
    '<label for="tgUser">Адрес бота без @</label><input type="text" id="tgUser" value="'+esc(s.bot_user||'')+'" placeholder="my_zapis_bot">'+
    '<button class="btn" data-a="saveTg">Сохранить</button> '+
    (s.tg_on?'<span class="tag ok" style="margin-left:10px">токен на месте</span>':'<span class="tag bad" style="margin-left:10px">токена нет</span>')+
    '</div>';

  h+='<div class="card"><h3>Шаг 2. Запустить реле</h3><div style="height:12px"></div>'+
    '<p class="muted">На вашем Маке, один раз в терминале. Программа лежит рядом с сайтом — скачайте и запустите:</p>'+
    '<div class="note mono">curl -O '+esc(origin)+'relay/psy_relay.py<br>'+
    'python3 psy_relay.py --url '+esc(origin)+'api.php --key '+esc(s.relay_key)+'</div>'+
    '<button class="btn-ghost btn-sm" data-a="copy" data-txt="curl -O '+esc(origin)+'relay/psy_relay.py &amp;&amp; python3 psy_relay.py --url '+esc(origin)+'api.php --key '+esc(s.relay_key)+'">Скопировать команду</button>'+
    '<div class="field-hint" style="margin-top:12px">Пока реле работает — уведомления уходят мгновенно. Закрыли ноутбук — сообщения ждут в очереди и уйдут при следующем запуске.</div>'+
    '<div style="height:10px"></div>'+
    '<table><tr><th style="width:190px">В очереди сейчас</th><td><b>'+(q.pending||0)+'</b></td></tr>'+
    '<tr><th>Реле выходило на связь</th><td>'+esc(q.lastRelay||'ни разу')+'</td></tr>'+
    '<tr><th>Планировщик отработал</th><td>'+esc(q.lastTick||'—')+'</td></tr></table></div>';

  h+=tgPeopleCard();
  h+='<div class="card"><h3>Шаг 3. Подключить себя и специалистов</h3><div style="height:12px"></div>';
  if(!s.bot_user) h+='<div class="note warn">Сначала укажите адрес бота в шаге 1 — без него персональные ссылки не собираются.</div>';
  else{
    h+='<p class="muted">Ваша личная ссылка координатора — по ней бот будет слать новые записи, отмены, неявки и все оценки ниже 4:</p>'+
      '<div class="note mono">'+esc(D.adminTgLink)+'</div>'+
      '<button class="btn-ghost btn-sm" data-a="copy" data-txt="'+esc(D.adminTgLink)+'">Скопировать</button>'+
      '<div class="field-hint" style="margin-top:12px">Ссылки специалисток — во вкладке «Специалисты». Клиент подключается сам: кнопка появляется у него сразу после записи.</div>';
  }
  return h+'</div>';
}

function curatorCard(D){
  var key=(S.tgList&&S.tgList.adminKey)||'';
  return '<div class="card"><div class="eyebrow">Кураторы</div><h3>Как подключить куратора</h3>'+
    '<p class="muted" style="margin:8px 0 12px">Куратор видит в боте все заявки, отмены, неявки и низкие оценки. '+
    'Доступ к дашборду — по общей ссылке координатора и коду.</p>'+
    '<div class="note mono">'+esc(D.adminTgLink)+'</div>'+
    '<button class="btn-ghost btn-sm" data-a="copy" data-txt="'+esc(D.adminTgLink)+'">Скопировать ссылку</button>'+
    (key?' <button class="btn-ghost btn-sm" data-a="copy" data-txt="'+esc(key)+'" style="margin-left:8px">Скопировать ключ</button>':'')+
    '<div class="field-hint" style="margin-top:12px">Ссылку нужно <b>открыть</b>, а не искать бота в поиске: при поиске Telegram не передаёт хвост ссылки, '+
    'и бот не понимает, кто пришёл. Если так уже вышло — пусть человек просто отправит боту сообщением ключ:'+
    (key?' <span class="mono">'+esc(key)+'</span>':'')+'</div></div>';
}
function tgPeopleCard(){
  var L=(S.tgList&&S.tgList.list)||null;
  var h='<div class="card"><div class="split"><h3>Кто подключён к боту</h3>'+
    '<button class="btn-ghost btn-sm" data-a="tgList">'+(L?'Обновить':'Показать')+'</button></div>';
  if(!L) return h+'<div class="field-hint" style="margin-top:10px">Здесь можно сделать человека куратором или отключить его от бота.</div></div>';
  if(!L.length) return h+'<div class="empty">К боту пока никто не подключён.</div></div>';
  h+='<div style="height:12px"></div><div class="scroll"><table><tr><th>Кто</th><th>Роль</th><th>Последняя активность</th><th></th></tr>';
  var names={admin:'куратор',expert:'специалист',client:'клиентка'};
  L.forEach(function(p){
    h+='<tr><td><b>'+esc(p.fname||p.expert||('chat '+p.chat))+'</b>'+(p.uname?' <span class="muted">@'+esc(p.uname)+'</span>':'')+
       (p.expert?'<div class="muted">'+esc(p.expert)+'</div>':'')+'</td>'+
       '<td>'+(p.role==='admin'?'<span class="tag ok">куратор</span>':'<span class="tag">'+(names[p.role]||p.role)+'</span>')+'</td>'+
       '<td class="muted">'+esc(p.at)+'</td>'+
       '<td style="white-space:nowrap">'+
       (p.role!=='admin'?'<button class="btn-link" data-a="tgMakeAdmin" data-chat="'+esc(p.chat)+'">сделать куратором</button> ':'')+
       '<button class="btn-link" data-a="tgUnbind" data-chat="'+esc(p.chat)+'" style="color:var(--alarm)">отключить</button>'+
       '</td></tr>';
  });
  return h+'</table></div></div>';
}

function aMessages(){
  var D=S.adm;
  var soon=D.bookings.filter(function(b){return b.status!=='cancelled' && !isPast(b.dt);});
  var due=D.bookings.filter(function(b){return isPast(b.dt) && !b.csat && b.status!=='cancelled' && b.status!=='noshow';});
  var origin=location.origin+location.pathname.replace(/index\.html$/,'');

  var h='<div class="card"><div class="note">Запасной путь для тех, кто не подключил Telegram. У кого в колонке TG стоит галочка — тем бот пишет сам, дублировать не нужно.</div></div>';

  h+='<div class="card"><h3>Напоминания записавшимся ('+soon.length+')</h3><div style="height:12px"></div>';
  if(!soon.length) h+='<div class="empty">Предстоящих встреч нет.</div>';
  var bot=(D.settings.bot_user||'');
  soon.forEach(function(b){
    var txt='Здравствуйте, '+b.client_name+'! Напоминаем о встрече '+fmtFull(b.dt)+'. Специалист — '+(b.ename||'—')+
      '. Ссылка: '+(b.link||'пришлём отдельно')+'. Если время не подходит, ответьте на это сообщение — перенесём.';
    if(!b.tg_chat && bot)
      txt+=' Чтобы напоминания приходили сами, откройте: https://t.me/'+bot+'?start=b'+b.code;
    /* для SMS текст короче: длинные сообщения делятся на части и стоят дороже */
    var host=(D.settings.site_url||'').replace(/^https?:\/\//,'');
    var smsTxt='Напоминаем: '+String(b.dt).slice(8,10)+'.'+String(b.dt).slice(5,7)+
      ' в '+fmtTime(b.dt)+' '+tzShort()+' встреча. Ссылка: '+host+'?code='+b.code;
    h+='<div style="border-top:1px solid #EFE7DB;padding-top:12px;margin-top:12px">'+
      '<div class="split"><div><b>'+esc(b.contact)+'</b> <span class="muted">· '+esc(fmtFull(b.dt))+'</span> '+
      (b.tg_chat?'<span class="tag ok">бот пишет сам</span>':'<span class="tag wait">вручную</span>')+'</div>'+
      '<div style="white-space:nowrap">'+
      (!b.tg_chat&&b.phone&&D.settings.sms_on==='1'
        ? '<button class="btn-ghost btn-sm" data-a="smsText" data-id="'+b.id+'" data-txt="'+esc(smsTxt)+'">Отправить SMS</button> ':'')+
      '<button class="btn-ghost btn-sm" data-a="copy" data-txt="'+esc(txt)+'">Скопировать</button></div></div>'+
      '<div class="muted" style="margin-top:6px">'+esc(txt)+'</div></div>';
  });
  h+='</div>';

  h+='<div class="card"><h3>Запросить отзыв ('+due.length+')</h3><div style="height:12px"></div>';
  if(!due.length) h+='<div class="empty">Все отзывы собраны.</div>';
  due.forEach(function(b){
    var txt='Здравствуйте, '+b.client_name+'! Спасибо, что пришли на встречу. Оцените её, пожалуйста, — это две минуты: '+
      origin+' , код записи '+b.code+'.';
    var smsTxt='Спасибо за встречу! Оцените, это 2 мин: '+origin.replace(/^https?:\/\//,'')+'?code='+b.code;
    h+='<div style="border-top:1px solid #EFE7DB;padding-top:12px;margin-top:12px">'+
      '<div class="split"><div><b>'+esc(b.contact)+'</b> <span class="muted">· код '+esc(b.code)+'</span></div>'+
      '<div style="white-space:nowrap">'+
      (!b.tg_chat&&b.phone&&D.settings.sms_on==='1'
        ? '<button class="btn-ghost btn-sm" data-a="smsText" data-id="'+b.id+'" data-txt="'+esc(smsTxt)+'">Отправить SMS</button> ':'')+
      '<button class="btn-ghost btn-sm" data-a="copy" data-txt="'+esc(txt)+'">Скопировать</button></div></div>'+
      '<div class="muted" style="margin-top:6px">'+esc(txt)+'</div></div>';
  });
  return h+'</div>';
}

function aSettings(){
  var s=S.adm.settings;
  return '<div class="card"><h3>Правила распределения</h3><div style="height:12px"></div>'+
    '<div class="row"><div><label for="sQuota">Квота клиентов на специалиста</label>'+
    '<input type="number" id="sQuota" min="1" max="50" value="'+s.quota+'"></div>'+
    '<div><label for="sDur">Длительность встречи, мин</label>'+
    '<input type="number" id="sDur" min="15" max="180" step="5" value="'+s.duration+'"></div></div>'+
    '<label style="font-weight:400;display:flex;gap:9px;align-items:center;margin-bottom:10px">'+
    '<input type="checkbox" id="sDig" style="width:auto;margin:0"'+(s.digest_on==='1'?' checked':'')+'>'+
    '<span style="font-size:13px">присылать кураторам итоги дня</span></label>'+
    '<label for="sDigAt">Во сколько присылать итоги</label>'+
    '<input type="text" id="sDigAt" value="'+esc(s.digest_at||'21:00')+'" placeholder="21:00">'+
    '<label style="font-weight:400;display:flex;gap:9px;align-items:flex-start;margin:0 0 6px">'+
    '<input type="checkbox" id="sRules" style="width:auto;margin:3px 0 0"'+(s.rules_gate==='1'?' checked':'')+'>'+
    '<span style="font-size:13px"><b>Показывать время только тех, кто принял договорённости</b><br>'+
    '<span class="muted">Пока специалист не поставил галочку в кабинете, его часы клиенткам не показываются '+
    'и клиенты ему не назначаются. Кто ещё не принял — видно во вкладке «Специалисты».</span></span></label>'+
    '<div class="field-hint" style="margin-bottom:14px">Включайте, когда специалисты предупреждены: '+
    'в момент включения из выдачи пропадут все, кто ещё не отметился.</div>'+
    '<div class="note" style="margin:0 0 14px">Встречи специалисты ведут у себя. Ссылка на свою комнату — '+
    'обязательное поле кабинета: пока её нет, время открыть нельзя и в записи специалиста нет. '+
    'Никаких видео-аккаунтов школы система не заводит и не хранит.</div>'+
    '<label for="sOrg">Название на шапке</label><input type="text" id="sOrg" value="'+esc(s.org)+'">'+
    (TECH?'<label for="sPin">Код координатора — всё, кроме настроек</label><input type="text" id="sPin" value="'+esc(s.admin_pin)+'">'+
      '<label for="sOwn">Код владельца — плюс настройки и интеграции</label><input type="text" id="sOwn" value="'+esc(s.owner_pin||'')+'">'+
      '<div class="field-hint">Координаторы входят по первому коду и видят дашборд, записи, специалистов, Telegram и рассылку. '+
      'Настройки и ключи — только по второму.</div>':'')+
    '<div class="row"><div><label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" id="sR24" style="width:auto"'+(s.remind24==='1'?' checked':'')+'><span style="font-size:13px">напоминать за сутки</span></label></div>'+
    '<div><label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" id="sR2" style="width:auto"'+(s.remind2==='1'?' checked':'')+'><span style="font-size:13px">напоминать за 2 часа</span></label></div>'+
    '<div><label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" id="sR15" style="width:auto"'+(s.remind15==='1'?' checked':'')+'><span style="font-size:13px">напоминать за 15 минут</span></label></div>'+
    '<div><label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" id="sAsk" style="width:auto"'+(s.ask_csat==='1'?' checked':'')+'><span style="font-size:13px">спрашивать отзыв</span></label></div></div>'+
    '<div style="height:8px"></div><button class="btn" data-a="saveCfg">Сохранить</button>'+
    '</div>'+
    (!TECH?'<div class="card"><div class="eyebrow">SMS</div><h3>Подпись отправителя</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Имя, которое клиентка видит вместо номера, когда приходит SMS. '+
    'Оно должно в точности совпадать с подписью, подтверждённой в вашем кабинете SMS Aero, — иначе провайдер '+
    'начнёт отбивать сообщения, и напоминания перестанут доходить.</p>'+
    '<table style="margin-bottom:14px"><tr><th style="width:220px">SMS</th><td>'+
      (s.sms_on==='1'?'<span class="tag ok">включены</span>':'<span class="tag">выключены</span>')+
      (s.sms_ready?'':' <span class="tag bad">ключ не задан</span>')+'</td></tr></table>'+
    '<label for="smSign">Подпись отправителя</label>'+
    '<input type="text" id="smSign" value="'+esc(s.sms_sign||'')+'" placeholder="SMS Aero">'+
    '<div class="field-hint">Проверить, какие подписи вам разрешены, можно кнопкой ниже — она же покажет баланс.</div>'+
    '<div style="height:12px"></div><button class="btn" data-a="saveCfg">Сохранить</button> '+
    '<button class="btn-ghost btn-sm" data-a="smsCheck" style="margin-left:8px">Показать баланс и доступные подписи</button> '+
    '<button class="btn-ghost btn-sm" data-a="smsLog" style="margin-left:8px">Последние отправки</button>'+
    (S.smsLog?'<div class="scroll" style="margin-top:14px"><table>'+
      (S.smsLog.length?S.smsLog.map(function(r){
        var okk=String(r.txt).indexOf('ok ')===0;
        return '<tr><td class="muted" style="white-space:nowrap;width:120px">'+esc(String(r.at).slice(5,16))+'</td>'+
               '<td>'+(okk?'<span class="tag ok">ушла</span> ':'<span class="tag bad">не ушла</span> ')+
               esc(String(r.txt).replace(/^(ok|fail) /,''))+'</td></tr>';
      }).join(''):'<tr><td class="muted">Отправок пока не было.</td></tr>')+'</table></div>':'')+
    (S.smsInfo?'<div class="note" style="margin-top:14px">Баланс: <b>'+esc(String(S.smsInfo.balance))+' ₽</b>'+
      (S.smsInfo.signs&&S.smsInfo.signs.length?'<br>Доступные подписи: '+esc(S.smsInfo.signs.filter(function(x){return x;}).join(', ')):'')+'</div>':'')+
    '<div class="field-hint" style="margin-top:12px">Логин и ключ SMS Aero спрятаны, чтобы их случайно не стереть.</div>'+
    '</div>':'')+
    (TECH?'<div class="card"><div class="eyebrow">Интеграции</div><h3>SMS для тех, кто не в Telegram</h3>'+
    '<p class="muted" style="margin:8px 0 14px">SMS Aero. Порядок такой: если клиентка подключила бота — пишем в Telegram; '+
    'если нет, но оставила телефон — шлём SMS; если ни того, ни другого — на почту. Дублей не будет.</p>'+
    '<label style="font-weight:400;display:flex;gap:9px;align-items:center;margin-bottom:14px">'+
    '<input type="checkbox" id="smOn" style="width:auto;margin:0"'+(s.sms_on==='1'?' checked':'')+'>'+
    '<span style="font-size:13px">слать SMS</span></label>'+
    '<div class="row"><div><label for="smMail">Логин (e-mail в SMS Aero)</label><input type="text" id="smMail" value="'+esc(s.sms_email||'')+'"></div>'+
    '<div><label for="smSign">Подпись отправителя</label><input type="text" id="smSign" value="'+esc(s.sms_sign||'')+'" placeholder="SMS Aero"></div></div>'+
    '<label for="smKey">API-ключ</label><input type="password" id="smKey" placeholder="'+(s.sms_ready?'сохранён — вставьте новый, чтобы заменить':'из личного кабинета SMS Aero')+'">'+
    '<div class="row"><div><label for="smPhone">Номер для тестовой SMS</label><input type="text" id="smPhone" placeholder="+7 999 123-45-67"></div></div>'+
    '<button class="btn" data-a="saveCfg">Сохранить</button> '+
    '<button class="btn-ghost btn-sm" data-a="smsCheck" style="margin-left:8px">Проверить и отправить тест</button>'+
    (S.smsInfo?'<div class="note" style="margin-top:14px">Баланс: <b>'+esc(String(S.smsInfo.balance))+' ₽</b>'+
      (S.smsInfo.signs&&S.smsInfo.signs.length?'<br>Доступные подписи: '+esc(S.smsInfo.signs.join(', ')):'')+
      (S.smsInfo.sent?'<br>Тестовая SMS отправлена.':'')+'</div>':'')+
    '<div class="field-hint" style="margin-top:10px">Каждая SMS платная. На клиентку без Telegram уходит до трёх штук: '+
    'подтверждение, напоминание за сутки и за два часа. Опрос после встречи по SMS не шлём.</div>'+
    '</div>'+
    '<div class="card"><div class="eyebrow">Интеграции</div><h3>Передача заявок во внешний агрегатор</h3>'+
    '<p class="muted" style="margin:8px 0 14px">При каждой записи отправляем POST с полями name, phone и метками utm_*. '+
    'Не дошло — повторяем в фоне, до пяти раз.</p>'+
    '<label style="font-weight:400;display:flex;gap:9px;align-items:center;margin-bottom:14px">'+
    '<input type="checkbox" id="hkOn" style="width:auto;margin:0"'+(s.hook_on==='1'?' checked':'')+'>'+
    '<span style="font-size:13px">передавать заявки</span></label>'+
    '<label for="hkUrl">Адрес приёма</label><input type="text" id="hkUrl" value="'+esc(s.hook_url||'')+'" placeholder="https://…/webhook/…">'+
    '<button class="btn" data-a="saveCfg">Сохранить</button> '+
    '<button class="btn-ghost btn-sm" data-a="hookTest" style="margin-left:8px">Отправить тестовую заявку</button>'+
    '</div>':'')+
    '<div class="card"><h3>Если реле замолчит</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Telegram живёт, пока работает реле на вашем компьютере. Если очередь стоит больше часа, '+
    'сервер напишет вам на почту — это единственный канал, который в такой момент точно работает.</p>'+
    '<label for="sMail">Почта для тревог</label><input type="text" id="sMail" value="'+esc(s.alert_email||'')+'" placeholder="вы@example.com">'+
    '<div class="field-hint">Письма с общего хостинга часто попадают в спам. Отправьте тестовое и, если найдёте его в спаме, отметьте «не спам» — иначе настоящую тревогу вы не увидите.</div>'+
    '<button class="btn-ghost btn-sm" data-a="testMail">Отправить тестовое письмо</button>'+
    '<div class="note" style="margin-top:18px">Квота — главный рычаг. Она решает, сколько клиентов достанется каждой: '+
    'выставили 5 при 10 специалистах — получите ровно 50 мест и закрытое обещание для десяти выпускниц.</div></div>'+
    (TECH?'<div class="card"><h3>Очистить данные пилота</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Удаляет все слоты и записи разом. Отдельные записи удаляются во вкладке «Записи».</p>'+
    '<button class="btn-ghost" data-a="wipe">Удалить слоты и записи</button></div>'
    :'<div class="card"><div class="field-hint">Технические настройки — подключение бота, ключи SMS, код доступа и полная очистка — спрятаны, '+
      'чтобы их нельзя было сломать случайно. Чтобы открыть их, добавьте к адресу страницы <span class="mono">?tech=1</span></div></div>');
}

function loadStats(){
  var m=S.statMonth||new Date().toISOString().slice(0,7), from, to;
  if(m==='all'){
    from=(S.stats&&S.stats.firstDay)||'2026-08-01';
    to=new Date().toISOString().slice(0,10);
  }else{
    from=m+'-01';
    var d=new Date(from); d.setMonth(d.getMonth()+1); d.setDate(0);
    to=d.toISOString().slice(0,10);
  }
  return api('stats',{from:from,to:to},'admin')
    .then(function(j){ S.stats=j.stats; render(); })
    .catch(function(e){ toast(e.message); });
}

function loadClients(){
  return api('clients',{},'admin')
    .then(function(j){ S.clients=j.clients||[]; render(); })
    .catch(function(e){ toast(e.message); });
}

/* Запоминаем отмеченное, но ещё не сохранённое */
function keepDraft(){
  var list=[];
  app.querySelectorAll('.topicBox').forEach(function(x){ if(x.checked) list.push(x.value); });
  S.topicDraft=list;
}

/* ══════════ СОБЫТИЯ ══════════ */
function bind(){
  app.querySelectorAll('[data-a]').forEach(function(el){
    el.addEventListener('click',function(ev){ act(el.getAttribute('data-a'),el,ev); });
  });
  if(S.doneA){
    app.querySelectorAll('[data-a="'+S.doneA+'"]').forEach(function(b){
      b.classList.remove('busy'); b.classList.add('done'); b.disabled=false;
      if(b.tagName==='BUTTON' && (b.classList.contains('btn')||b.classList.contains('btn-ghost')||b.classList.contains('btn-link')))
        b.innerHTML=S.doneLabel;
    });
  }
  var d=document.getElementById('dDate');
  if(d) d.addEventListener('change',function(){ S.draftDate=d.value; S.draftTimes=[]; render(); });
  var hf=document.getElementById('dHalf');
  if(hf) hf.addEventListener('change',function(){ S.halfHours=hf.checked; S.draftTimes=[]; render(); });
  var ci=document.getElementById('codeIn');
  if(ci) ci.addEventListener('keydown',function(e){ if(e.key==='Enter') act('openCode'); });
  app.querySelectorAll('[data-q]').forEach(function(inp){
    inp.addEventListener('change',function(){
      var id=inp.getAttribute('data-q');
      api('expert_quota',{id:id,quota:inp.value},'admin')
        .then(function(j){ toast('Квота: '+j.quota); return loadAdmin(); }).then(render)
        .catch(function(e){ toast(e.message); });
    });
  });
  var bq=document.getElementById('bookQ');
  if(bq) bq.addEventListener('input',function(){
    var pos=bq.selectionStart; S.bookQ=bq.value; render();
    var n=document.getElementById('bookQ');
    if(n){ n.focus(); try{ n.setSelectionRange(pos,pos); }catch(e){} }
  });
  var mc=document.getElementById('mCat');
  if(mc) mc.addEventListener('change',function(){
    S.mTopic=mc.value; S.mExpert=''; S.mDt='';
    S.mName=val('mName'); S.mPhone=val('mPhone'); S.mTg=val('mTg');
    render();
  });
  var mx=document.getElementById('mExp');
  if(mx) mx.addEventListener('change',function(){
    S.mExpert=mx.value; S.mDt='';
    S.mName=val('mName'); S.mPhone=val('mPhone'); S.mTg=val('mTg');
    render();
  });
  var md=document.getElementById('mDt');
  if(md) md.addEventListener('change',function(){ S.mDt=md.value; });
  var es=document.getElementById('expSearch');
  if(es){
    es.addEventListener('input',function(){
      S.expSearch=es.value;
      var pos=es.selectionStart; render();
      var n=document.getElementById('expSearch');
      if(n){ n.focus(); try{ n.setSelectionRange(pos,pos); }catch(e){} }
    });
  }
  app.querySelectorAll('.topicBox').forEach(function(x){ x.addEventListener('change', keepDraft); });
  var ts=document.getElementById('topicSel');
  if(ts) ts.addEventListener('change',function(){ S.topicPick=ts.value; render(); });
  var rb=document.getElementById('renameBox');
  if(rb){
    rb.focus();
    rb.addEventListener('keydown',function(ev){
      if(ev.key==='Enter'){ var b=app.querySelector('[data-a="renameDo"]'); if(b) b.click(); }
      if(ev.key==='Escape') act('renameCancel');
    });
  }
  var sm=document.getElementById('statMonth');
  if(sm) sm.addEventListener('change',function(){ S.statMonth=sm.value; render(); loadStats(); });
  var cs=document.getElementById('cliSearch');
  if(cs){
    cs.addEventListener('input',function(){
      S.cliSearch=cs.value;
      var pos=cs.selectionStart; render();
      var n=document.getElementById('cliSearch');
      if(n){ n.focus(); try{ n.setSelectionRange(pos,pos); }catch(e){} }
    });
  }
  var ap=document.getElementById('aPin');
  if(ap) ap.addEventListener('keydown',function(e){ if(e.key==='Enter') act('aLogin'); });
}

/* Кнопки, за которыми идёт запрос к серверу: их гасим на время ожидания
   и подсвечиваем зелёным, когда всё получилось. */
var ASYNC=['book','saveTopics','eSaveLink','addRange','addSlots','delSlot','saveReport','aLogin','eLogin',
  'addExpert','toggleExpert','togglePairs','expertDel','fixSurnames','saveCfg','saveTg','acceptRules',
  'testMail','smsCheck','smsLog','hookTest','adminBook','editSave','adminCancel','adminDelete','swapDo',
  'tgList','tgMakeAdmin','tgUnbind','sendCsat','cancelCode','moveTo','openCode','wipe'];
function busyBtn(el){
  if(!el || !el.classList) return;
  el.classList.add('busy');
  if(el.tagName==='BUTTON'){
    el.dataset.was=el.innerHTML;
    el.disabled=true;
    if(el.classList.contains('btn')||el.classList.contains('btn-ghost')) el.innerHTML='Секунду…';
  }
}
function doneBtn(a,label){
  S.doneA=a; S.doneLabel=label||'Готово ✓';
  render();
  setTimeout(function(){ if(S.doneA===a){ S.doneA=null; S.doneLabel=''; render(); } },2200);
}
/* мгновенная подсветка кнопки, которая не вызывает перерисовку */
function doneHere(el,label){
  if(!el||el.tagName!=='BUTTON') return;
  var was=el.dataset.was||el.innerHTML;
  el.classList.remove('busy'); el.classList.add('done'); el.disabled=false;
  el.innerHTML=label||'Готово ✓';
  setTimeout(function(){ el.classList.remove('done'); el.innerHTML=was; },2000);
}

function act(a,el,ev){
  if(ASYNC.indexOf(a)>=0) busyBtn(el);
  if(a==='reload'){ location.reload(); return; }
  if(a==='home'){ S.view=startView(); S.topic=''; S.topicPick=''; S.pair=null; S.gender=''; S.pickedSlot=null; S.codeView=null; S.moveMode=false; boot().then(render); render(); return; }
  if(a==='client'){ S.view='client'; boot().then(render); render(); return; }
  if(a==='pick'){ S.pickedSlot=el.getAttribute('data-t'); render(); return; }
  if(a==='topic'){ S.topic=el.getAttribute('data-v'); S.pair=null; S.gender=''; S.pickedSlot=null; render(); return; }
  if(a==='topicGo'){
    if(!S.topicPick) return toast('Выберите запрос из списка.');
    S.topic=S.topicPick; S.pair=null; S.gender=''; S.pickedSlot=null; render(); return;
  }
  if(a==='topicBack'){ S.topic=''; S.topicPick=''; S.pair=null; S.gender=''; S.pickedSlot=null; render(); return; }
  if(a==='pair'){ S.pair=el.getAttribute('data-v'); S.gender=''; S.pickedSlot=null; render(); return; }
  if(a==='pairBack'){ S.pair=null; S.gender=''; S.pickedSlot=null; render(); return; }
  if(a==='gender'){ S.gender=el.getAttribute('data-v'); S.pickedSlot=null; render(); return; }

  /* --- запись --- */
  if(a==='book'){
    if(!document.getElementById('cAgree').checked) return toast('Отметьте согласие на обработку данных.');
    var name=val('cName'), contact=val('cContact');
    if(name.length<2) return toast('Напишите, как к вам обращаться.');
    if(contact.length<4) return toast('Оставьте Telegram или телефон — туда придёт напоминание.');
    S.busy=true; render();
    api('book',Object.assign({dt:S.pickedSlot,name:name,contact:contact,category:S.topic,
                note:val('cNote'),email:val('cMail'),pair:S.pair==='1'?'1':'0',gender:S.gender||''},UTM))
      .then(function(j){
        S.busy=false; S.lastBooking=j.booking; S.pickedSlot=null; S.view='done'; render();
      })
      .catch(function(e){
        S.busy=false; toast(e.message);
        boot().then(function(){ S.pickedSlot=null; render(); });
      });
    return;
  }

  /* --- запись по коду --- */
  if(a==='openCode'){
    var v=(document.getElementById('codeIn')||{}).value||'';
    v=v.trim().toUpperCase();
    if(v.length<4) return toast('Введите код из пяти символов.');
    api('booking&code='+encodeURIComponent(v))
      .then(function(j){ S.codeView=j.booking; S.codeErr=''; S.view='code'; render(); })
      .catch(function(e){ S.codeView=null; S.codeErr=e.message; S.view='code'; render(); });
    return;
  }
  if(a==='moveStart'){ S.moveMode=true; render(); boot().then(render); return; }
  if(a==='moveCancel'){ S.moveMode=false; render(); return; }
  if(a==='moveTo'){
    var nt=el.getAttribute('data-t');
    if(!confirm('Перенести запись на '+fmtFull(nt)+'?')) return;
    api('reschedule',{code:S.codeView.code,dt:nt})
      .then(function(j){ S.codeView=j.booking; S.moveMode=false; toast('Перенесли. Новое время: '+fmtTime(nt)); render(); return boot(); })
      .then(render)
      .catch(function(e){ toast(e.message); boot().then(render); });
    return;
  }
  if(a==='cancelCode'){
    if(!confirm('Отменить запись? Слот освободится для другой клиентки.')) return;
    api('cancel_by_code',{code:S.codeView.code})
      .then(function(){ toast('Запись отменена.'); return api('booking&code='+encodeURIComponent(S.codeView.code)); })
      .then(function(j){ S.codeView=j.booking; render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='star'){
    app.querySelectorAll('.star').forEach(function(s){ s.setAttribute('aria-pressed','false'); });
    el.setAttribute('aria-pressed','true'); return;
  }
  if(a==='sendCsat'){
    var picked=app.querySelector('.star[aria-pressed=true]');
    if(!picked) return toast('Поставьте оценку от 1 до 5.');
    api('feedback',{code:S.codeView.code,score:Number(picked.getAttribute('data-v')),
      fit:val('fFit'),nps:Number(val('fNps')),comment:val('fCom')})
      .then(function(){ toast('Спасибо, отзыв записан.'); return api('booking&code='+encodeURIComponent(S.codeView.code)); })
      .then(function(j){ S.codeView=j.booking; render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }

  /* --- специалист --- */
  if(a==='expertGo'){
    if(tok('expert')){ S.view='expert'; render(); loadExpert().then(render); }
    else { S.view='expertLogin'; render(); }
    return;
  }
  if(a==='eLogin'){
    var id=val('eSel');
    if(!id) return toast('Выберите себя в списке.');
    api('login',{role:'expert',expertId:id}).then(function(j){
      setTok('expert',j.token); S.view='expert'; S.draftDate=todayISO(); S.draftTimes=[]; render();
      return loadExpert().then(render);
    }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='eLogout'){ setTok('expert',''); S.exp=null; S.pickedExpert=null; S.view='expertLogin'; boot().then(render); render(); return; }
  if(a==='eGender'){
    /* шлём только пол: темы этот запрос не касается */
    api('save_topics',{genders:el.getAttribute('data-v')},'expert')
      .then(function(){ toast('Сохранено.'); return loadExpert(); }).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='topicGroup'){
    var g=el.getAttribute('data-v'), on=el.getAttribute('data-on')==='1';
    app.querySelectorAll('.topicBox[data-g="'+g+'"]').forEach(function(x){ x.checked=on; });
    el.textContent=on?'снять весь раздел':'отметить весь раздел';
    el.setAttribute('data-on', on?'0':'1');
    keepDraft();
    return;
  }
  if(a==='acceptRules'){
    var box=document.getElementById('rulesBox');
    if(!box || !box.checked) return toast('Поставьте галочку — это подтверждение, что вы прочли.');
    api('accept_rules',{},'expert')
      .then(function(){ toast('Спасибо! Ваше время открыто для записи.'); return loadExpert(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='saveTopics'){
    var list=[];
    app.querySelectorAll('.topicBox').forEach(function(x){ if(x.checked) list.push(x.value); });
    if(!list.length) return toast('Отметьте хотя бы одну тему — иначе клиентки вас не найдут.');
    api('save_topics',{topics:list,pairs:document.getElementById('ePairs')&&document.getElementById('ePairs').checked?'1':'0'},'expert')
      .then(function(){ S.topicDraft=null; toast('Темы сохранены.'); return loadExpert(); }).then(function(){ doneBtn('saveTopics','Сохранено ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='eSaveLink'){
    api('save_link',{link:val('eLink')},'expert')
      .then(function(){ toast('Ссылка сохранена.'); return loadExpert(); }).then(function(){ doneBtn('eSaveLink','Сохранено ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='time'){
    var t=el.getAttribute('data-v'), i=S.draftTimes.indexOf(t);
    if(i>=0) S.draftTimes.splice(i,1); else S.draftTimes.push(t);
    render(); return;
  }
  if(a==='addRange'){
    var day=val('dDate')||todayISO();
    var f=val('tFrom'), t=val('tTo'), st=parseInt(val('tStep'),10)||50, rep=Number(val('dRep'))||1;
    S.tFrom=f; S.tTo=t; S.tStep=String(st);
    var toMin=function(v){ var p=v.split(':'); return (+p[0])*60+(+p[1]); };
    var a1=toMin(f), a2=toMin(t);
    if(a2<a1) return toast('Конец раньше начала — поменяйте местами.');
    var list=[];
    for(var m=a1;m<=a2 && list.length<40;m+=st){
      list.push((Math.floor(m/60)<10?'0':'')+Math.floor(m/60)+':'+(m%60<10?'0':'')+(m%60));
    }
    if(!list.length) return toast('Не получилось разложить промежуток — проверьте шаг.');
    api('slots_add',{date:day,times:list,repeat:rep},'expert').then(function(j){
      S.draftDate=day;
      var msg=j.added?('Открыто слотов: '+j.added):'Новых слотов не добавилось.';
      if(j.busy) msg+=', пропущено занятых в общем кабинете: '+j.busy;
      toast(msg);
      return loadExpert();
    }).then(function(){ doneBtn('addRange','Время открыто ✓'); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='addSlots'){
    var day=val('dDate')||todayISO(), times=S.draftTimes.slice(), rep=Number(val('dRep'))||1;
    if(!times.length) return;
    api('slots_add',{date:day,times:times,repeat:rep},'expert').then(function(j){
      S.draftTimes=[]; S.draftDate=day;
      toast('Открыто слотов: '+j.added);
      return loadExpert();
    }).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='delSlot'){
    api('slot_del',{id:el.getAttribute('data-id')},'expert')
      .then(loadExpert).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='eCancel'){
    var why=prompt('Отменить встречу?\n\nКлиентке уйдёт сообщение об отмене, ваше время вернётся в общий пул '+
      'и его сможет занять другая.\n\nЕсли просто переносите — напишите координатору, он подберёт новое время.\n\n'+
      'Причина (координатор её увидит, можно пусто):');
    if(why===null) return;
    api('expert_cancel',{id:el.getAttribute('data-id'),reason:why},'expert')
      .then(function(){ toast('Встреча отменена, клиентке сообщили.'); return loadExpert(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='confYes'||a==='confNo'||a==='confClear'){
    var v=(a==='confYes')?'yes':(a==='confNo')?'no':'';
    api('confirm_client',{id:el.getAttribute('data-id'),value:v},'expert')
      .then(function(){
        toast(v==='yes'?'Отметила: клиент подтвердил.':v==='no'?'Отметила: не отвечает. Координатор увидит.':'Отметка снята.');
        return loadExpert();
      }).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='repEdit'){ S.repEdit=el.getAttribute('data-id'); render(); return; }
  if(a==='repCancel'){ S.repEdit=null; render(); return; }
  if(a==='saveReport'){
    var bid=el.getAttribute('data-id');
    var wasEdit=String(S.repEdit)===String(bid);
    var f=function(n){ var x=app.querySelector('[data-f="'+n+'"][data-id="'+bid+'"]'); return x?x.value:''; };
    api('report',{id:bid,status:f('status'),req:f('req'),plan:f('plan'),next:f('next')},'expert')
      .then(function(){ toast(wasEdit?'Отчёт поправлен.':'Отчёт сдан.'); S.repEdit=null; return loadExpert(); })
      .then(function(){ doneBtn('saveReport',wasEdit?'Правка сохранена ✓':'Отчёт сдан ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }

  /* --- координатор --- */
  if(a==='adminGo'){
    if(tok('admin')){ S.view='admin'; render(); loadAdmin().then(render); }
    else { S.view='adminLogin'; render(); }
    return;
  }
  if(a==='aLogin'){
    api('login',{role:'admin',pin:val('aPin')}).then(function(j){
      setTok('admin',j.token); S.view='admin'; render();
      return loadAdmin().then(render);
    }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='aTab'){
    S.adminTab=el.getAttribute('data-v'); render();
    if(S.adminTab==='cli' && !S.clients) loadClients();
    if(S.adminTab==='stat' && !S.stats) loadStats();
    return;
  }
  if(a==='aLogout'){
    setTok('admin','');
    S.adm=null; S.adminTab='dash'; S.tgList=null; S.smsInfo=null;
    S.swapFor=null; S.lastImport=null; S.lastAdminBooking=null;
    S.view='adminLogin'; render();
    return;
  }
  if(a==='mFmt'){
    S.mPair=(el.getAttribute('data-v')==='1');
    S.mExpert=''; S.mDt=''; S.mName=val('mName'); S.mPhone=val('mPhone'); S.mTg=val('mTg');
    render(); return;
  }
  if(a==='adminBook'){
    if(!S.mTopic) return toast('Сначала выберите тему.');
    var dt=val('mDt');
    if(!dt) return toast('Выберите время из списка.');
    if(val('mName').length<2) return toast('Впишите имя клиентки.');
    if(!val('mPhone') && !val('mTg')) return toast('Нужен телефон или Telegram — хотя бы одно.');
    api('admin_book',{dt:dt,expertId:val('mExp'),name:val('mName'),
                      phone:val('mPhone'),tg_nick:val('mTg'),
                      category:S.mTopic,note:val('mNote'),pair:S.mPair?'1':'0'},'admin')
      .then(function(j){
        S.lastAdminBooking=j.booking; S.mName=''; S.mPhone=''; S.mTg=''; S.mDt='';
        toast(j.booking.sms ? ('Записана к '+j.booking.expert+'. SMS отправлена.')
                            : ('Записана к '+j.booking.expert+'.'));
        return loadAdmin();
      }).then(function(){ doneBtn('adminBook','Записана ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='editStart'){
    var eid=el.getAttribute('data-id');
    S.editFor=(String(S.editFor)===String(eid))?null:eid;
    render(); return;
  }
  if(a==='editCancel'){ S.editFor=null; render(); return; }
  if(a==='editSave'){
    if(val('edName').length<2) return toast('Впишите имя клиента.');
    if(!val('edPhone') && !val('edTg')) return toast('Нужен телефон или Telegram — хотя бы одно.');
    api('booking_edit',{id:el.getAttribute('data-id'),name:val('edName'),phone:val('edPhone'),
                        tg_nick:val('edTg'),email:val('edMail'),category:val('edCat'),note:val('edNote')},'admin')
      .then(function(j){
        toast(j.changed?'Данные обновлены.':'Менять было нечего.');
        S.editFor=null; return loadAdmin();
      }).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='adminCancel'){
    if(!confirm('Отменить эту запись? Слот вернётся в общий пул, обеим сторонам уйдёт уведомление.')) return;
    api('admin_cancel',{id:el.getAttribute('data-id')},'admin')
      .then(function(){ toast('Отменено.'); return loadAdmin(); }).then(render)
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='swapStart'){
    var id=el.getAttribute('data-id');
    S.swapFor=id; S.openBook=id; S.moveAdmin=null; S.adminTab='book'; render(); return;
  }
  if(a==='swapCancel'){ S.swapFor=null; render(); return; }
  if(a==='unassign'){
    var why=prompt('Снять специалиста с этой записи?\n\nКлиентке НИЧЕГО не уйдёт, время за ней останется. '+
      'Специалист перестанет видеть встречу у себя.\n\nПричина (для истории, можно пусто):');
    if(why===null) return;
    api('unassign_booking',{id:el.getAttribute('data-id'),reason:why},'admin')
      .then(function(){ toast('Специалист снят. Запись ждёт нового.'); return loadAdmin(); })
      .then(function(){ doneBtn('unassign','Снято ✓'); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='assignDo'){
    api('assign_booking',{id:el.getAttribute('data-id'),expertId:val('swExp')},'admin')
      .then(function(j){
        S.swapFor=null;
        toast(j.via ? ('Назначено: '+j.expert+'. Клиентке ушло: '+({tg:'Telegram',sms:'SMS',mail:'почта'}[j.via]||j.via))
                    : ('Назначено: '+j.expert+'. Клиентке отправить нечем — сообщите вручную.'));
        return loadAdmin();
      }).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='swapDo'){
    api('change_expert',{id:el.getAttribute('data-id'),expertId:val('swExp'),reason:val('swWhy')},'admin')
      .then(function(j){ S.swapFor=null; toast('Встречу передали: '+j.expert); return loadAdmin(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='moveStartAdmin'){ S.moveAdmin=el.getAttribute('data-id'); render(); return; }
  if(a==='moveCancelAdmin'){ S.moveAdmin=null; render(); return; }
  if(a==='moveDoAdmin'){
    var dt=val('mvDt');
    if(!dt) return toast('Выберите новое время.');
    api('admin_reschedule',{id:el.getAttribute('data-id'),dt:dt},'admin')
      .then(function(j){ S.moveAdmin=null; toast('Перенесли на '+fmtFull(j.dt)+', ведёт '+j.expert); return loadAdmin(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='trashShow'){
    api('trash_list',{},'admin').then(function(j){ S.trash=j.list; render(); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='trashHide'){ S.trash=null; render(); return; }
  if(a==='trashRestore'){
    if(!confirm('Вернуть запись? Клиентке уйдёт подтверждение с новой ссылкой на встречу.')) return;
    api('trash_restore',{id:el.getAttribute('data-id')},'admin')
      .then(function(j){ toast('Восстановлена, код '+j.code); return api('trash_list',{},'admin'); })
      .then(function(j){ S.trash=j.list; return loadAdmin(); }).then(render)
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='qaTab'){ S.qaTab=el.getAttribute('data-v'); S.qaOpen=null; render(); return; }
  if(a==='qaFlt'){ S.qaFlt=el.getAttribute('data-v'); render(); return; }
  if(a==='qaFlt2'){ S.qaFlt2=el.getAttribute('data-v'); render(); return; }
  if(a==='qaOpen'){
    var id=el.getAttribute('data-id');
    S.qaOpen=(String(S.qaOpen)===String(id))?null:id; render(); return;
  }
  if(a==='bookFlt'){ S.bookFlt=el.getAttribute('data-v'); render(); return; }
  if(a==='openBook'){
    var id=el.getAttribute('data-id');
    S.openBook=(String(S.openBook)===String(id))?null:id;
    S.histFor=null; S.histLog=null; render(); return;
  }
  if(a==='histFor'){
    var id=el.getAttribute('data-id');
    if(String(S.histFor)===String(id)){ S.histFor=null; S.histLog=null; render(); return; }
    S.histFor=id; S.histLog=null; render();
    api('booking_log&id='+encodeURIComponent(id),null,'admin')
      .then(function(j){ S.histLog=j.events; render(); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='smsText'){
    var t=el.getAttribute('data-txt')||'';
    if(!confirm('Отправить это SMS?\n\n'+t)) return;
    api('sms_booking',{id:el.getAttribute('data-id'),text:t},'admin')
      .then(function(){ toast('SMS отправлена.'); return loadAdmin(); })
      .then(function(){ doneBtn('smsText','Отправлено ✓'); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='smsOne'){
    if(!confirm('Отправить клиентке SMS с напоминанием о встрече?')) return;
    api('sms_booking',{id:el.getAttribute('data-id')},'admin')
      .then(function(){ toast('SMS отправлена.'); return loadAdmin(); })
      .then(function(){
        doneBtn('smsOne','SMS ушла ✓');
        if(S.histFor) api('booking_log&id='+encodeURIComponent(S.histFor),null,'admin')
          .then(function(j){ S.histLog=j.events; render(); });
      }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='adminDelete'){
    if(!confirm('Удалить запись?\n\nЧеловек об этом НЕ УЗНАЕТ, ссылка на встречу перестанет работать.\n'+
      'Если встреча просто не состоится — нажмите «Отменить встречу», тогда клиентка получит уведомление.\n\n'+
      'Копия попадёт в корзину, вернуть можно в течение месяца.')) return;
    api('booking_delete',{id:el.getAttribute('data-id')},'admin')
      .then(function(){ toast('Запись удалена.'); return loadAdmin(); }).then(render)
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='tgList'){
    api('tg_list',{},'admin').then(function(j){ S.tgList=j; render(); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='tgMakeAdmin'){
    if(!confirm('Сделать этого человека куратором? Он начнёт получать все заявки и уведомления.')) return;
    api('tg_role',{chat:el.getAttribute('data-chat'),role:'admin'},'admin')
      .then(function(){ toast('Готово, куратор подключён.'); return api('tg_list',{},'admin'); })
      .then(function(j){ S.tgList=j; render(); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='tgUnbind'){
    if(!confirm('Отключить от бота? Уведомления перестанут приходить.')) return;
    api('tg_unbind',{chat:el.getAttribute('data-chat')},'admin')
      .then(function(){ toast('Отключено.'); return api('tg_list',{},'admin'); })
      .then(function(j){ S.tgList=j; render(); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='smsLog'){
    api('logs&kind=sms',null,'admin')
      .then(function(j){ S.smsLog=j.rows||[]; render(); doneBtn('smsLog','Показала ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='smsCheck'){
    toast('Стучусь в SMS Aero…');
    api('sms_check',{sms_email:val('smMail'),sms_sign:val('smSign'),sms_key:val('smKey'),phone:val('smPhone')},'admin')
      .then(function(j){ S.smsInfo=j; toast(j.sent?'Отправила тестовую SMS.':'Связь есть.'); return loadAdmin(); })
      .then(function(){ doneBtn('smsCheck','Связь есть ✓'); }).catch(function(e){ S.smsInfo=null; toast(e.message); render(); });
    return;
  }
  if(a==='hookTest'){
    api('hook_test',{url:val('hkUrl')},'admin')
      .then(function(j){ toast('Приняли: '+j.answer); return loadAdmin(); }).then(function(){ doneBtn('hookTest','Приняли ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='testMail'){
    api('test_mail',{email:val('sMail')},'admin')
      .then(function(){ toast('Письмо ушло. Проверьте почту и папку «Спам».'); return loadAdmin(); }).then(function(){ doneBtn('testMail','Отправлено ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='addExpert'){
    api('expert_add',{name:val('nName'),direction:val('nDir'),link:val('nLink')},'admin')
      .then(function(j){ toast(esc(j.name)+' — добавлена в список.'); S.expSearch=''; return loadAdmin(); })
      .then(function(){ doneBtn('addExpert','Добавлена ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='digestShow'){
    api('digest_preview',{},'admin').then(function(j){ S.digest=j.text; render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='digestSend'){
    if(!confirm('Отправить сводку всем кураторам, подключённым к боту?')) return;
    api('digest_send',{},'admin')
      .then(function(j){ toast(j.sent?('Отправлено адресатам: '+j.sent):'Никто не подключён к боту — ушло письмом.'); })
      .then(function(){ doneBtn('digestSend','Отправлено ✓'); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='pauseExpert'){
    api('expert_pause',{id:el.getAttribute('data-id')},'admin')
      .then(function(j){ toast(j.paused?'Слоты закрыты — клиентки их больше не видят.':'Показ слотов включён.'); return loadAdmin(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='renameStart'){ S.renameFor=el.getAttribute('data-id'); render(); return; }
  if(a==='renameCancel'){ S.renameFor=null; render(); return; }
  if(a==='renameDo'){
    var box=document.getElementById('renameBox');
    if(!box) return;
    api('expert_rename',{id:el.getAttribute('data-id'),name:box.value},'admin')
      .then(function(j){ toast('Теперь: '+j.name); S.renameFor=null; return loadAdmin(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='prio'){
    api('expert_priority',{id:el.getAttribute('data-id'),value:el.getAttribute('data-v')},'admin')
      .then(function(j){ toast('Приоритет: '+({1:'зелёный',2:'жёлтый',3:'красный'}[j.priority])); return loadAdmin(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='toggleTest'){
    api('expert_test',{id:el.getAttribute('data-id')},'admin')
      .then(function(j){ toast(j.test?'Карточка отмечена как тестовая: уведомления кураторам с неё не уходят.':'Карточка снова боевая.'); return loadAdmin(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='togglePairs'){
    api('expert_pairs',{id:el.getAttribute('data-id')},'admin')
      .then(loadAdmin).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='toggleExpert'){
    api('expert_toggle',{id:el.getAttribute('data-id')},'admin')
      .then(loadAdmin).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='saveTg'){
    api('settings_save',{tg_token:val('tgTok'),bot_user:val('tgUser')},'admin')
      .then(function(){ toast('Сохранено.'); return loadAdmin(); }).then(function(){ doneBtn('saveTg','Сохранено ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='saveCfg'){
    var cfg={
      quota:val('sQuota'),duration:val('sDur'),org:val('sOrg'),admin_pin:val('sPin'),owner_pin:val('sOwn'),
      remind24:document.getElementById('sR24').checked?'1':'0',
      remind2:document.getElementById('sR2').checked?'1':'0',
      remind15:document.getElementById('sR15')?(document.getElementById('sR15').checked?'1':'0'):null,
      ask_csat:document.getElementById('sAsk').checked?'1':'0',
      alert_email:val('sMail'),
      rules_gate:document.getElementById('sRules')?(document.getElementById('sRules').checked?'1':'0'):null,
      digest_on:document.getElementById('sDig')?(document.getElementById('sDig').checked?'1':'0'):null,
      digest_at:val('sDigAt')
    };
    /* Переключатели шлём только если их поля есть на экране: в обычном режиме
       технические блоки скрыты, и «нет чекбокса» не должно значить «выключить». */
    if(document.getElementById('hkOn')){
      cfg.hook_on=document.getElementById('hkOn').checked?'1':'0'; cfg.hook_url=val('hkUrl');
    }
    if(document.getElementById('smOn')){
      cfg.sms_on=document.getElementById('smOn').checked?'1':'0';
      cfg.sms_email=val('smMail'); cfg.sms_key=val('smKey');
    }
    /* подпись живёт и в обычном режиме — шлём её, если поле на экране есть */
    if(document.getElementById('smSign')) cfg.sms_sign=val('smSign');
    api('settings_save',cfg,'admin').then(function(){ toast('Настройки сохранены.'); return loadAdmin(); }).then(function(){ doneBtn('saveCfg','Сохранено ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='wipe'){
    if(!confirm('Удалить все слоты и записи? Это не отменить.')) return;
    api('wipe',{},'admin').then(function(){ toast('Данные пилота очищены.'); return loadAdmin(); }).then(render)
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='statPeriod'){ S.statMonth=el.getAttribute('data-v'); render(); loadStats(); return; }
  if(a==='cliFlt'){ S.cliFlt=el.getAttribute('data-v'); render(); return; }
  if(a==='cliOpen'){
    var cid=el.getAttribute('data-id');
    S.cliOpen=(String(S.cliOpen)===String(cid))?null:cid; render(); return;
  }
  if(a==='cliCsv'){
    window.open('api.php?action=clients_csv&token='+encodeURIComponent(tok('admin')),'_blank');
    return;
  }
  if(a==='csv'){
    window.open('api.php?action=csv&token='+encodeURIComponent(tok('admin')),'_blank');
    return;
  }
  if(a==='copy'){
    var txt=el.getAttribute('data-txt')||'';
    if(navigator.clipboard) navigator.clipboard.writeText(txt).then(function(){toast('Скопировано.');doneHere(el,'Скопировано ✓');},function(){toast('Скопируйте вручную.');});
    else toast('Скопируйте текст вручную.');
    return;
  }
}

/* ══════════ ОБНОВЛЕНИЕ ══════════ */
function startPoll(){
  if(poll) return;
  poll=setInterval(function(){
    if(document.querySelector('input:focus, textarea:focus, select:focus')) return;
    if(S.view==='admin' && tok('admin')) loadAdmin().then(render);
    else if(S.view==='expert' && tok('expert')) loadExpert().then(render);
    else if(S.view==='client' || S.view==='home') boot().then(render);
  },30000);
}

/* ══════════ СТАРТ ══════════ */
var qs=new URLSearchParams(location.search);
var TECH=(qs.get('tech')==='1');
var UTM=(function(){
  var keys=['utm_source','utm_medium','utm_campaign','utm_term'], got={}, any=false;
  keys.forEach(function(k){ var v=qs.get(k); if(v){ got[k]=v.slice(0,110); any=true; } });
  try{
    if(any) localStorage.setItem('psy_utm',JSON.stringify(got));
    else got=JSON.parse(localStorage.getItem('psy_utm')||'{}');
  }catch(e){}
  return got||{};
})();
/* Считаем заходы обезличенно: день, метка источника и шаг воронки.
   Каждый шаг отмечается один раз за сеанс, чтобы перерисовки не накручивали счётчик. */
function hit(step){
  if(ROLE!=='client') return;
  try{
    var k='psy_hit_'+step;
    if(sessionStorage.getItem(k)) return;
    sessionStorage.setItem(k,'1');
  }catch(e){}
  api('hit',{step:step,source:UTM.utm_source||''}).catch(function(){});
}

boot().then(function(){
  hit('open');
  S.view=startView();
  var tab=qs.get('tab');
  if(ROLE==='admin' && ['dash','book','exp','tg','msg','set'].indexOf(tab)>=0) S.adminTab=tab;
  var c=qs.get('code');
  if(c && ROLE==='client'){
    return api('booking&code='+encodeURIComponent(c.toUpperCase()))
      .then(function(j){ S.codeView=j.booking; S.view='code'; })
      .catch(function(e){ S.codeErr=e.message; S.view='code'; });
  }
}).then(function(){
  render();
  if(S.view==='expert') loadExpert().then(render);
  if(S.view==='admin')  loadAdmin().then(render);
  startPoll();
});
})();
