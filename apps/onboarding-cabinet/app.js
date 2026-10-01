(function(){
'use strict';

/* ══════════ Сопровождение в профессию ══════════
   Устроено как школьная запись к психологу (/shkola): единый вход почтой
   и паролем («У меня есть аккаунт / Я здесь впервые»), роль выбирается
   при регистрации. Ученице доступ открывается сразу — по почте и
   телефону она подклеивается к списку из школы; менеджеру — после
   подтверждения владельцем. После входа у каждого свой кабинет. */

var API=(window.SOP_API||'api.php');
var S={
  view:'loading', boot:null, err:'',
  me:null,
  authTab:'login', pendingMsg:'', resetToken:'',
  /* ученица */
  my:null, k:'', pickedSlot:null, myTab:'main', myHow:false,
  codeView:null, codeErr:'', moveMode:false,
  /* кабинет менеджера и владельца */
  tab:'calls', mgr:null, schedOpen:false,
  repFor:null, rep:{note:'',status:'',next:'',next_dt:''},
  callsFilter:'today', callsMine:false, callsSearch:'',
  draftDate:'', draftTimes:[], draftHalf:false, draftLink:'',
  clients:null, statuses:{}, cliSearch:'', cliStatus:'', cliMineOnly:false, cliMissingOnly:false,
  card:null, cardBook:false, cardFields:false,
  users:null, mgrs:null, mgrOpen:null, pupilsOpen:false, moveFor:null, moveTimes:null, moveMgrs:null, pupilSearch:'', stats:null, statsPrev:null, statsCompare:false, sFrom:'', sTo:'',
  settings:null, tg:null,
  busy:false
};
var app=document.getElementById('sop-app');

function tok(){ try{ return localStorage.getItem('sop_tok')||''; }catch(e){ return ''; } }
function setTok(v){ try{ v?localStorage.setItem('sop_tok',v):localStorage.removeItem('sop_tok'); }catch(e){} }

/* ══════════ API ══════════ */
function api(action,body,noToken){
  var opt={method:body?'POST':'GET',headers:{}};
  var url=API+'?action='+action;
  var t=noToken?'':tok();
  if(t){
    opt.headers['Authorization']='Bearer '+t;       /* основной путь */
    if(body) body=Object.assign({},body,{token:t}); /* запасной: если Apache срежет заголовок */
    else url+='&token='+encodeURIComponent(t);
  }
  if(body){ opt.headers['Content-Type']='application/json'; opt.body=JSON.stringify(body); }
  return fetch(url,opt).then(function(r){
    return r.json().catch(function(){ throw new Error('Сервер ответил не по формату'); }).then(function(j){
      if(!j||!j.ok){
        var e=new Error((j&&j.error)||('Ошибка '+r.status)); e.status=r.status; throw e;
      }
      return j;
    });
  });
}

/* Файл нельзя завернуть в JSON — для загрузки свой путь. */
function apiFile(action,file){
  var fd=new FormData(); fd.append('photo',file);
  var t=tok();
  if(t) fd.append('token',t);
  if(S.k&&!t) fd.append('k',S.k);
  return fetch(API+'?action='+action+(t?'&token='+encodeURIComponent(t):''),
    {method:'POST',headers:t?{Authorization:'Bearer '+t}:{},body:fd})
    .then(function(r){ return r.json(); })
    .then(function(j){ if(!j||!j.ok) throw new Error((j&&j.error)||'Не получилось загрузить'); return j; });
}

/* ══════════ Утилиты ══════════ */
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
function fmtKratko(v){
  var m=['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
  var s=String(v||''); if(s.length<10) return s;
  return parseInt(s.slice(8,10),10)+' '+m[parseInt(s.slice(5,7),10)-1]+
    (s.length>=16?', '+s.slice(11,16):'');
}
function isPast(v){ return dt(v).getTime()<Date.now(); }
function todayISO(){
  var d=new Date(),p=function(n){return (n<10?'0':'')+n;};
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate());
}
function tzName(){
  var tz=(S.boot&&S.boot.tz)||'Europe/Moscow';
  return tz==='Europe/Moscow'?'московское':('в часовом поясе '+tz);
}
function toast(msg){
  var t=document.createElement('div');
  t.className='toast'; t.textContent=msg;
  document.body.appendChild(t);
  setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); },3600);
}
function metric(v,l,hot){ return '<div class="metric'+(hot?' hot':'')+'"><b>'+v+'</b><span>'+l+'</span></div>'; }
function hay(){ return Array.prototype.slice.call(arguments).join(' ').toLowerCase().replace(/[^0-9a-zа-яё@._ ]/gi,' '); }
function findSlot(times,id){ return (times||[]).filter(function(t){ return t.id===id; })[0]; }
/* «2 дня», «5 дней» — форма слова по числу */
function plural(n,f){
  n=Math.abs(n)%100; var n1=n%10;
  if(n>10&&n<20) return f[2];
  if(n1>1&&n1<5) return f[1];
  if(n1===1) return f[0];
  return f[2];
}
function copyText(s){
  if(navigator.clipboard&&navigator.clipboard.writeText){
    navigator.clipboard.writeText(s).then(function(){ toast('Скопировано'); },function(){ prompt('Скопируйте:',s); });
  }else prompt('Скопируйте:',s);
}
function val(id){ var el=document.getElementById(id); return el?String(el.value||'').trim():''; }
function checked(id){ var el=document.getElementById(id); return !!(el&&el.checked); }
var STATUS_TAG={
  booked:'<span class="tag wait">запись</span>',
  held:'<span class="tag ok">проведён</span>',
  noshow:'<span class="tag bad">неявка</span>',
  cancelled:'<span class="tag">отмена</span>'
};
var CLI_TAG={
  'new':'<span class="tag wait">новая</span>',
  work:'<span class="tag">в работе</span>',
  done:'<span class="tag ok">завершён</span>',
  refund:'<span class="tag bad">возврат</span>',
  frozen:'<span class="tag">заморозка</span>'
};
function stageTag(st){
  if(!st||st==='—') return '';
  if(st.indexOf('Просрочено')===0) return '<span class="tag bad">'+esc(st)+'</span>';
  if(st.indexOf('В графике')===0) return '<span class="tag ok">'+esc(st)+'</span>';
  return '<span class="tag">'+esc(st)+'</span>';
}

/* ══════════ Загрузка ══════════ */
function start(){
  var qp=new URLSearchParams(location.search);
  S.resetToken=qp.get('reset')||'';
  S.k=qp.get('k')||'';
  var code=(qp.get('code')||'').toUpperCase();
  /* Персональная ссылка менеджера (?rec=…): запоминаем в браузере, чтобы
     не потерялась, если ученица закрыла вкладку и вернулась позже, не
     дойдя до записи. */
  var recQp=qp.get('rec');
  try{
    if(recQp) localStorage.setItem('sop_rec',recQp);
    S.rec=recQp||localStorage.getItem('sop_rec')||'';
  }catch(e){ S.rec=recQp||''; }
  api('boot',S.rec?{rec:S.rec}:null,true).then(function(j){
    S.boot=j;
    if(S.resetToken){ S.view='reset'; render(); return; }
    if(code) return openCode(code);
    if(S.k) return loadMy().then(function(){ S.view=S.my?'my':'auth'; render(); });
    if(tok()){
      return api('me').then(function(j2){ signIn(j2,false); })
        .catch(function(){ setTok(''); S.view='auth'; render(); });
    }
    S.view='auth'; render();
  }).catch(function(e){
    S.err=e.message; S.view='errboot'; render();
  });
}
function signIn(j,fresh){
  S.me=j.me;
  if(fresh&&j.token) setTok(j.token);
  if(j.me.role==='client'){
    S.view='my'; render();
    loadMy().then(render);
  }else{  /* менеджер, куратор, владелец */
    S.view='cab'; S.tab='calls'; render();
    loadMgr().then(render);
  }
}
function loadMy(){
  var body=(S.k&&!tok())||S.rec?Object.assign({},S.k&&!tok()?{k:S.k}:{},S.rec?{rec:S.rec}:{}):null;
  return api('my_view',body).then(function(j){ S.my=j; })
    .catch(function(e){ if(e.status!==401) toast(e.message); });
}
function loadMgr(){
  return api('mgr_view').then(function(j){ S.mgr=j; })
    .catch(function(e){ if(e.status===401){ setTok(''); S.me=null; S.view='auth'; } else toast(e.message); });
}
function loadClients(){
  return api('clients').then(function(j){ S.clients=j.clients; S.statuses=j.statuses; }).catch(function(e){ toast(e.message); });
}
function openCode(code){
  return api('booking',{code:code},true).then(function(j){
    S.codeView=j.booking; S.codeErr=''; S.moveMode=false; S.view='code'; render();
  }).catch(function(e){ S.codeView=null; S.codeErr=e.message; S.view='code'; render(); });
}
function act(p){
  if(S.busy) return Promise.resolve();
  S.busy=true; render();
  return p.catch(function(e){ toast(e.message); })
    .then(function(r){ S.busy=false; render(); return r; });
}

/* ══════════ Рендер ══════════ */
function render(){
  var h='';
  if(S.view==='loading') h='<div class="wrap"><div class="card"><div class="empty">Загружаем…</div></div></div>';
  else if(S.view==='errboot') h='<div class="wrap"><div class="card"><h2>Сервер недоступен</h2><p class="muted" style="margin-top:8px">'+esc(S.err)+'</p><button class="btn" data-a="reload">Попробовать снова</button></div></div>';
  else if(S.view==='auth') h='<div class="wrap">'+brand()+vAuth()+'</div>';
  else if(S.view==='pending') h='<div class="wrap">'+brand()+vPending()+'</div>';
  else if(S.view==='reset') h='<div class="wrap">'+brand()+vReset()+'</div>';
  else if(S.view==='my') h='<div class="wrap">'+brand()+vMy()+'</div>';
  else if(S.view==='code') h='<div class="wrap">'+brand()+vCode()+'</div>';
  else if(S.view==='cab') h=vCab();
  app.innerHTML=h;
  bind();
}
function brand(){
  return '<div class="topbar"><div class="brandmark"><div class="dot"></div><span>'+esc((S.boot&&S.boot.org)||'Сопровождение в профессию')+'</span></div>'+
    (S.me?'<button class="btn-link" data-a="logout">Выйти ('+esc(S.me.name)+')</button>'
         :(S.view==='code'||S.view==='my'?'<button class="btn-link" data-a="toAuth">Вход</button>':''))+'</div>';
}

/* ---------- Вход и регистрация — как в школе ---------- */
function vAuth(){
  var tab=S.authTab||'login';
  var h='<div class="card"><div class="eyebrow">Сопровождение в профессию</div>'+
    '<h1>'+(tab==='login'?'Вход':tab==='forgot'?'Смена пароля':'Регистрация')+'</h1>'+
    '<div class="chips" style="margin:16px 0">'+
      '<button class="chip" aria-pressed="'+(tab==='login')+'" data-a="authTab" data-v="login">У меня есть аккаунт</button>'+
      '<button class="chip" aria-pressed="'+(tab==='reg')+'" data-a="authTab" data-v="reg">Я здесь впервые</button>'+
    '</div>';
  if(S.pendingMsg) h+='<div class="note">'+esc(S.pendingMsg)+'</div>';
  if(S.rec&&S.boot&&S.boot.recManager)
    h+='<div class="note">Вы пришли по ссылке менеджера <b>'+esc(S.boot.recManager)+'</b> — после входа увидите время только у неё.</div>';

  if(tab==='login'){
    h+='<label for="aEmail">Почта</label><input type="email" id="aEmail" autocomplete="email" placeholder="вы@example.com">'+
      '<label for="aPass">Пароль</label><input type="password" id="aPass" autocomplete="current-password">'+
      '<button class="btn'+(S.busy?' busy':'')+'" data-a="doLogin">'+(S.busy?'Заходим…':'Войти')+'</button>'+
      '<div style="height:10px"></div>'+
      '<button class="btn-link" data-a="authTab" data-v="forgot">Забыли пароль?</button>';
  }else if(tab==='forgot'){
    h+='<p class="muted" style="margin-bottom:14px">Впишите почту — пришлём ссылку, чтобы задать новый пароль.</p>'+
      '<label for="aEmail">Почта</label><input type="email" id="aEmail" autocomplete="email">'+
      '<button class="btn'+(S.busy?' busy':'')+'" data-a="doForgot">Прислать ссылку</button>'+
      '<div style="height:10px"></div><button class="btn-link" data-a="authTab" data-v="login">Вернуться ко входу</button>';
  }else{
    var roles=(S.boot&&S.boot.roles)||{client:'ученица',manager:'менеджер онбординга',admin:'куратор'};
    h+='<label for="rName">Имя и фамилия</label><input type="text" id="rName" placeholder="Мария Иванова" autocomplete="name">'+
      '<label for="rEmail">Почта</label><input type="email" id="rEmail" autocomplete="email" placeholder="вы@example.com">'+
      '<div class="field-hint">Для учениц — та, на которую оформлен курс: по ней мы находим вас в списках школы.</div>'+
      '<div class="row"><div><label for="rPhone">Телефон</label>'+
      '<input type="tel" id="rPhone" autocomplete="tel" placeholder="+7 999 123-45-67"></div>'+
      '<div><label for="rTg">Telegram</label>'+
      '<input type="text" id="rTg" placeholder="@nickname"></div></div>'+
      '<div class="field-hint">Ученицам: по телефону или телеграму мы тоже вас найдём, даже если курс оформлен '+
      'на другую почту. Менеджерам телефон нужен, чтобы связаться, когда что-то срочное.</div>'+
      '<label for="rPass">Пароль</label><input type="password" id="rPass" autocomplete="new-password" placeholder="минимум шесть символов">'+
      '<label for="rRole">Кто вы</label><select id="rRole">'+
        Object.keys(roles).map(function(k){ return '<option value="'+k+'"'+(S.rec&&k==='client'?' selected':'')+'>'+esc(roles[k])+'</option>'; }).join('')+
      '</select>'+
      '<div class="field-hint">Ученицам доступ открывается сразу. Менеджерам и кураторам — после подтверждения владельцем: это доступ к чужим личным данным.</div>'+
      '<button class="btn'+(S.busy?' busy':'')+'" data-a="doReg">'+(S.busy?'Отправляем…':'Зарегистрироваться')+'</button>';
  }
  return h+'</div>';
}
function vPending(){
  return '<div class="card"><div class="eyebrow">Почти всё</div><h1>Заявка отправлена</h1>'+
    '<p class="muted" style="margin-top:12px">'+esc(S.pendingMsg||'Владелец подтвердит доступ — мы напишем на почту.')+'</p>'+
    '<button class="btn-ghost" style="margin-top:16px" data-a="authTab" data-v="login">Ко входу</button></div>';
}
function vReset(){
  return '<div class="card"><div class="eyebrow">Новый пароль</div><h1>Задайте пароль</h1>'+
    '<div style="height:14px"></div>'+
    '<label>Пароль (минимум 6 символов)</label><input type="password" id="f-pass" autocomplete="new-password">'+
    '<button class="btn'+(S.busy?' busy':'')+'" data-a="resetDo">Сохранить и войти</button></div>';
}

/* ---------- Кабинет ученицы ----------
   Написан её глазами, а не изнутри системы: никаких внутренних статусов
   («новая», «в работе») — вместо них человеческие слова о том, что будет
   дальше. Прогресс показываем только когда он есть: «0 из 5» на первом
   экране встречает пустотой вместо приветствия. */
function vMy(){
  var M=S.my;
  if(!M) return '<div class="card"><div class="empty">Загружаем…</div></div>';
  var tabs='<div class="tabs" style="margin-bottom:16px">'+
    '<button class="tab" aria-selected="'+(S.myTab==='main')+'" data-a="myTab" data-v="main">Мои созвоны</button>'+
    '<button class="tab" aria-selected="'+(S.myTab==='profile')+'" data-a="myTab" data-v="profile">Профиль</button>'+
  '</div>';
  if(S.myTab==='profile') return tabs+vMyProfile();
  var target=M.target||5;
  var firstName=String(M.name||'').trim().split(/\s+/)[0]||'';
  var h=tabs;

  /* Приветствие: имя + одна понятная строка о том, что впереди */
  h+='<div class="card"><h1>'+
    (M.photo?'<img class="avatar avatar-sm" src="'+esc(M.photo)+'" alt=""> ':'')+
    (firstName?'Здравствуйте, '+esc(firstName)+'!':'Здравствуйте!')+'</h1>';
  if(M.status==='done'){
    h+='<p style="margin-top:10px">Все созвоны прошли. Поздравляем — вы дошли до конца!</p>';
  }else if(M.held>0){
    h+='<p style="margin-top:10px">'+progressDots(M.held,target)+'</p>'+
       '<p class="muted">'+(M.held===1?'Первый созвон прошёл':'Прошло '+M.held+' из '+target+' созвонов')+'.</p>';
  }else{
    h+='<p style="margin-top:10px">Первые 10 дней с вами будет менеджер: '+target+' коротких созвонов, '+
      'чтобы вы спокойно разобрались с учёбой.</p>';
  }
  h+='</div>';

  /* Что это вообще такое — свёрнуто, чтобы не мешать тем, кто уже понял.
     Текст нарочно живой: ученица приходит с тревогой «а вдруг не осилю»,
     сухая инструкция эту тревогу только усиливает. */
  h+='<div class="card"><div class="split" style="align-items:center">'+
    '<h3>Что вас ждёт</h3>'+
    '<button class="btn-link" data-a="myHowToggle">'+(S.myHow?'свернуть':'рассказать')+'</button></div>';
  if(S.myHow){
    h+='<div style="margin-top:14px;line-height:1.75">'+
      '<p>У вас есть менеджер — он на связи первые 10 дней. Созвонимся '+target+' раз '+
      'по '+((S.boot&&S.boot.duration)||30)+' минут.</p>'+
      '<p>Обсудим, когда вам удобно учиться, с чего начать и что делать, если что-то '+
      'не получается. Спрашивать можно о чём угодно — если сначала непонятно, это нормально.</p>'+
      '<p>Созвон по видеосвязи, ссылку пришлём заранее. Готовиться не нужно.</p>'+
      '<p style="margin-bottom:0">После всех встреч отправим сертификат и мини-курс в подарок — '+
      'выберете сами, какой.</p>'+
    '</div>';
  }
  h+='</div>';

  if(M.booking){
    var b=M.booking;
    var days=Math.round((dt(b.dt).setHours(0,0,0,0)-new Date().setHours(0,0,0,0))/86400000);
    var when=days===0?'Сегодня':days===1?'Завтра':(days>1?'Через '+days+' '+plural(days,['день','дня','дней']):'');
    var today=b.dt.slice(0,10)===todayISO();
    h+='<div class="card"><div class="eyebrow">Ближайший созвон</div>'+
      '<h2 style="margin-bottom:4px">'+esc(fmtFull(b.dt))+'</h2>'+
      (when?'<p class="muted" style="margin-bottom:12px">'+when+' · время '+esc(tzName())+'</p>':'')+
      '<p style="margin-bottom:14px">Ваш менеджер: <b>'+esc(b.manager)+'</b></p>'+
      (today
        ? (b.link
            ?'<a class="btn" href="'+esc(b.link)+'" target="_blank" rel="noopener">Перейти на созвон</a>'
            :'<div class="note">Ссылку пришлём перед началом — она появится здесь и в напоминании.</div>')
        :'<div class="note">Ссылка появится здесь в день созвона. Напомним '+
          (M.tgOn?'в Telegram и на почту':'на почту')+' за сутки и за два часа.</div>')+
      '<p class="muted" style="margin-top:14px">Не сможете в это время? '+
      '<button class="btn-link" data-a="openCodeVal" data-v="'+esc(b.code)+'">Перенести или отменить</button></p>'+
    '</div>';
  }else if(M.status==='done'){
    h+='<div class="card"><h2>Сопровождение завершено 🎉</h2>'+
      (M.certNote?'<div class="note" style="margin-top:12px">'+esc(M.certNote)+'</div>':'')+
      '<p style="margin-top:8px" class="muted">Нужен ещё созвон — напишите менеджеру.</p></div>';
  }else{
    var times=M.times||[];
    h+='<div class="card"><h3>'+(M.held>0?'Выберите время следующего созвона':'Выберите время первого созвона')+'</h3>'+
      '<p class="muted" style="margin:8px 0 16px">Выберите удобное время. Время '+esc(tzName())+'.</p>';
    if(M.recManager) h+='<div class="note" style="margin-bottom:16px">Запись к менеджеру <b>'+esc(M.recManager)+
      '</b>. <button class="btn-link" data-a="recClear">Показать всех менеджеров</button></div>';
    if(!times.length) h+='<div class="empty">'+
      (M.recManager?'У '+esc(M.recManager)+' сейчас нет свободного времени.<br><button class="btn-link" data-a="recClear">Посмотреть остальных менеджеров</button>'
        :'Свободного времени сейчас нет.<br>Загляните завтра — менеджеры открывают новое время каждый день.')+'</div>';
    else{
      var byDay={},order=[];
      times.forEach(function(t){ var d=String(t.dt).slice(0,10); if(!byDay[d]){byDay[d]=[];order.push(d);} byDay[d].push(t); });
      order.forEach(function(d){
        h+='<div class="day"><div class="day-head"><b>'+esc(fmtDay(byDay[d][0].dt))+'</b></div><div class="tickets">';
        byDay[d].forEach(function(t){
          h+='<button class="ticket" aria-pressed="'+(S.pickedSlot===t.id)+'" data-a="pick" data-id="'+t.id+'">'+
             fmtTime(t.dt)+'<small>'+esc(t.manager_name)+'</small></button>';
        });
        h+='</div></div>';
      });
      var picked=findSlot(times,S.pickedSlot);
      if(picked)
        h+='<p style="margin-top:8px">Вы выбрали: <b>'+fmtFull(picked.dt)+'</b> — менеджер <b>'+esc(picked.manager_name)+'</b></p>'+
           '<button class="btn'+(S.busy?' busy':'')+'" data-a="doBook">'+(S.busy?'Записываем…':'Записаться')+'</button>';
    }
    h+='</div>';
  }

  /* История — только то, что уже прошло: будущая запись выше, дублировать её незачем */
  var past=(M.bookings||[]).filter(function(b){ return b.status!=='booked'||isPast(b.dt); });
  if(past.length){
    h+='<div class="card"><h3>Прошедшие созвоны</h3>';
    past.forEach(function(b){
      h+='<div style="border-top:1px solid var(--line);padding-top:14px;margin-top:14px">'+
        '<div class="split"><div><b>'+fmtFull(b.dt)+'</b>'+(b.manager?' — '+esc(b.manager):'')+'</div>'+(STATUS_TAG[b.status]||'')+'</div>'+
        (b.pub?'<div class="rep-note" style="margin-top:8px"><b>Итоги от менеджера:</b>\n'+esc(b.pub)+'</div>':'')+
      '</div>';
    });
    h+='</div>';
  }

  if(M.botLink&&!M.tgOn){
    h+='<div class="card"><h3>Напоминания в Telegram</h3>'+
      '<p class="muted" style="margin:8px 0 12px">Бот напомнит о созвоне, пришлёт ссылку и поможет перенести, если планы изменятся.</p>'+
      '<a class="btn-tg" href="'+esc(M.botLink)+'" target="_blank" rel="noopener">Подключить Telegram</a></div>';
  }
  return h;
}
/* Точки прогресса: наглядно и без цифр-«из пяти» на пустом месте */
function progressDots(done,total){
  var h='<span class="dots">';
  for(var i=0;i<total;i++) h+='<i class="dot'+(i<done?' on':'')+'"></i>';
  return h+'</span>';
}

/* ---------- Профиль ученицы ---------- */
function vMyProfile(){
  var M=S.my;
  var h='<div class="card"><div class="eyebrow">Профиль</div>'+
    '<div class="split" style="align-items:center"><div style="display:flex;align-items:center;gap:16px">'+
      (M.photo?'<img class="avatar" src="'+esc(M.photo)+'" alt="Фото">':'<div class="avatar avatar-empty">'+esc((M.name||'?').charAt(0).toUpperCase())+'</div>')+
      '<div><h2>'+esc(M.name)+'</h2><div class="muted">'+({new:'знакомимся',work:'онбординг идёт',done:'онбординг завершён'}[M.status]||'')+'</div></div>'+
    '</div><div>'+
      '<button class="btn-ghost btn-sm" data-a="photoPick">'+(M.photo?'Сменить фото':'Загрузить фото')+'</button> '+
      (M.photo?'<button class="btn-link" data-a="photoDel">убрать</button>':'')+
      '<input type="file" id="f-photo" accept="image/jpeg,image/png,image/webp" style="display:none">'+
    '</div></div>'+
    '<div class="field-hint" style="margin-top:10px">Фото видит ваш менеджер — так на созвоне вы узнаете друг друга. JPG, PNG или WEBP до 3 МБ.</div>'+
  '</div>';
  h+='<div class="card"><h3>Ваши курсы и программы</h3>'+
    (M.course
      ?'<div class="chips" style="margin-top:12px">'+M.course.split(',').map(function(x){ return '<span class="chip" style="cursor:default">'+esc(x.trim())+'</span>'; }).join('')+'</div>'+
       '<p class="muted">Список приходит из школы — если чего-то не хватает, скажите менеджеру.</p>'
      :'<p class="muted" style="margin-top:8px">Курсы пока не отмечены — они подтянутся из школы. Если вы уже купили курс, скажите менеджеру.</p>')+
  '</div>';
  h+='<div class="card"><h3>Контакты</h3>'+
    '<label style="margin-top:10px">Имя и фамилия</label><input type="text" id="f-myname" value="'+esc(M.name||'')+'">'+
    '<label>Почта</label><input type="text" value="'+esc(M.email||'')+'" disabled style="opacity:.7">'+
    '<div class="field-hint">Почта — ваш вход в кабинет, по ней школа находит вас в списках. Чтобы сменить — напишите менеджеру.</div>'+
    '<div class="row"><div><label>Телефон</label><input type="tel" id="f-myphone" value="'+esc(M.phone?'+'+M.phone:'')+'" placeholder="+7 999 123-45-67"></div>'+
    '<div><label>Telegram (без @)</label><input type="text" id="f-mytg" value="'+esc(M.tg||'')+'"></div></div>'+
    (M.hasAccount?'<label>Новый пароль (пусто — не менять)</label><input type="password" id="f-mypass" autocomplete="new-password">':'')+
    '<button class="btn'+(S.busy?' busy':'')+'" data-a="myProfileSave">Сохранить</button>'+
  '</div>';
  if(M.botLink&&!M.tgOn){
    h+='<div class="card"><h3>Напоминания в Telegram</h3>'+
      '<p class="muted" style="margin:8px 0 12px">Подключите бота — напомнит о созвоне и даст перенести в одно нажатие.</p>'+
      '<a class="btn-tg" href="'+esc(M.botLink)+'" target="_blank" rel="noopener">Подключить Telegram</a></div>';
  }else if(M.tgOn){
    h+='<div class="card"><h3>Telegram</h3><p><span class="tag ok">подключён</span> — напоминания приходят в бота.</p></div>';
  }
  return h;
}

/* ---------- Запись по коду: перенос и отмена ---------- */
function vCode(){
  var b=S.codeView;
  if(!b) return '<div class="card"><h2>Запись не найдена</h2><p class="muted" style="margin-top:8px">'+
    esc(S.codeErr||'Проверьте код — пять символов из подтверждения.')+'</p><button class="btn-ghost" data-a="toMy">Назад</button></div>';

  var canMove=b.status==='booked';
  var h='<div class="card"><div class="eyebrow">Ваша запись</div><h2>'+esc(fmtFull(b.dt))+'</h2><div style="height:14px"></div>'+
    '<table><tr><th style="width:140px">Менеджер</th><td>'+esc(b.manager)+'</td></tr>'+
    '<tr><th>Ссылка</th><td>'+(b.link?'<a href="'+esc(b.link)+'" target="_blank" rel="noopener">Открыть созвон</a>':'уточняется')+'</td></tr>'+
    '<tr><th>Статус</th><td>'+(STATUS_TAG[b.status]||esc(b.status))+'</td></tr>'+
    '<tr><th>Код записи</th><td><span class="code">'+esc(b.code)+'</span></td></tr></table>';
  if(b.tg&&!b.tgOn&&canMove) h+='<div style="height:16px"></div><a class="btn-tg" href="'+esc(b.tg)+'" target="_blank" rel="noopener">Напоминания в Telegram</a>';
  if(canMove)
    h+='<div style="height:16px"></div>'+
       '<button class="btn-ghost btn-sm" data-a="moveStart">Перенести на другое время</button> '+
       '<button class="btn-link" data-a="cancelCode" style="margin-left:10px">Отменить запись</button>'+
       '<div class="note" style="margin-top:16px"><b>Менеджера нет на созвоне?</b> '+
       'Такое случается — пропал интернет, сдвинулась предыдущая встреча. '+
       'Не ждите дольше <b>10 минут</b>: нажмите «Перенести на другое время» и выберите новый час. '+
       'Ваше место остаётся за вами, пока вы не выберете новое.</div>';
  h+='</div>';

  if(S.moveMode&&canMove){
    var times=b.times||[];
    h+='<div class="card"><div class="eyebrow">Перенос</div><h3>Выберите новое время</h3>'+
       '<p class="muted" style="margin:8px 0 14px">Старое место держим за вами, пока вы не выберете новое.</p>';
    if(!times.length) h+='<div class="empty">Сейчас нет других свободных слотов.<br>Загляните позже — расписание пополняется.</div>';
    else{
      var days={},order=[];
      times.forEach(function(t){ var d=String(t.dt).slice(0,10); if(!days[d]){days[d]=[];order.push(d);} days[d].push(t); });
      order.forEach(function(d){
        h+='<div class="day"><div class="day-head"><b>'+esc(fmtDay(days[d][0].dt))+'</b></div><div class="tickets">';
        days[d].forEach(function(t){
          h+='<button class="ticket" data-a="moveTo" data-id="'+t.id+'">'+fmtTime(t.dt)+'<small>'+esc(t.manager_name)+'</small></button>';
        });
        h+='</div></div>';
      });
    }
    h+='<button class="btn-link" data-a="moveCancel">Передумала, оставить как было</button></div>';
    return h;
  }
  if(b.status==='cancelled') h+='<div class="card"><div class="empty">Запись отменена.<br>Хотите вернуться — выберите новое время в кабинете.</div></div>';
  return h+'<button class="btn-ghost" data-a="toMy">← В кабинет</button>';
}

/* ---------- Кабинет менеджера и владельца ---------- */
function vCab(){
  var role=S.me&&S.me.role;
  var owner=role==='owner', curator=role==='admin';
  var tabs=[['calls','Созвоны']];
  tabs.push(['cli','Ученицы']);
  if(curator||owner) tabs.push(['mgrs','Менеджеры']);
  if(curator) tabs.push(['stats','Статистика']);
  if(owner) tabs=tabs.concat([['people','Команда'],['stats','Статистика'],['settings','Настройки'],['tg','Telegram']]);
  tabs.push(['profile','Профиль']);
  var nav=tabs.map(function(t){
    return '<button data-a="tab" data-v="'+t[0]+'" aria-selected="'+(S.tab===t[0])+'">'+t[1]+'</button>';
  }).join('');
  var body='';
  if(!S.mgr) body='<div class="empty">Загружаем кабинет…</div>';
  else if(S.tab==='calls') body=vCalls();
  else if(S.tab==='cli') body=S.card?vCard():vClients();
  else if(S.tab==='mgrs') body=vManagers();
  else if(S.tab==='people') body=vPeople();
  else if(S.tab==='stats') body=vStats();
  else if(S.tab==='settings') body=vSettings();
  else if(S.tab==='tg') body=vTg();
  else if(S.tab==='profile') body=vProfile();
  var warn='';
  if(S.mgr&&S.mgr.botLink&&!S.mgr.tgOn&&S.tab==='calls')
    warn='<div class="card" style="background:#FBEEEA;border-left:4px solid var(--bad)">'+
      '<h3>Подключите Telegram</h3><p class="muted" style="margin:6px 0 10px">Пока бот не подключён, о новых записях придётся узнавать из этого кабинета руками.</p>'+
      '<a class="btn btn-sm" href="'+esc(S.mgr.botLink)+'" target="_blank" rel="noopener">Подключить</a></div>';
  return '<div class="shell">'+
    '<div class="side"><div class="who"><b>'+esc(S.me.name)+'</b><span>'+(S.me.role==='owner'?'владелец':S.me.role==='admin'?'куратор':'менеджер')+'</span></div>'+
    '<div class="nav">'+nav+'</div>'+
    '<div style="padding:12px"><button class="btn-link" data-a="logout">Выйти</button></div></div>'+
    '<div class="main">'+warn+body+'</div></div>';
}

/* ---------- Созвоны ---------- */
function chip(label,key,valv,cur){
  return '<button class="chip" data-a="chip" data-k="'+key+'" data-v="'+valv+'" aria-pressed="'+(cur===valv)+'">'+label+'</button>';
}
function vCalls(){
  var role=S.me&&S.me.role;
  var rows=(S.mgr&&S.mgr.bookings)||[];
  var meId=S.mgr&&S.mgr.meId;
  var f=S.callsFilter;
  var today=todayISO();
  var tomorrow=(function(){ var d=new Date(); d.setDate(d.getDate()+1); return isoOf(d); })();
  var weekEnd=(function(){ var d=new Date(); d.setDate(d.getDate()+6); return isoOf(d); })();
  var activeFuture=rows.filter(function(b){ return b.status==='booked'&&!isPast(b.dt); });
  var todayAll=rows.filter(function(b){ return b.dt.slice(0,10)===today&&b.status!=='cancelled'; });
  var todayCnt=todayAll.length;
  var tomorrowCnt=activeFuture.filter(function(b){ return b.dt.slice(0,10)===tomorrow; }).length;
  var weekCnt=activeFuture.filter(function(b){ return b.dt.slice(0,10)>=today&&b.dt.slice(0,10)<=weekEnd; }).length;
  var waitRep=rows.filter(function(b){ return b.status==='held'&&!b.rep_at; });
  var list=rows.filter(function(b){
    if(S.callsMine&&b.manager_id!=meId) return false;
    if(S.callsSearch&&hay(b.client_name,b.manager_name,b.course).indexOf(S.callsSearch.toLowerCase())<0) return false;
    if(f==='today')    return b.dt.slice(0,10)===today&&b.status!=='cancelled';
    if(f==='tomorrow') return b.status==='booked'&&b.dt.slice(0,10)===tomorrow;
    if(f==='week')     return b.status==='booked'&&b.dt.slice(0,10)>=today&&b.dt.slice(0,10)<=weekEnd;
    if(f==='future')   return b.status==='booked'&&!isPast(b.dt);
    if(f==='rep')      return b.status==='held'&&!b.rep_at;
    if(f==='past')     return b.status!=='booked'||isPast(b.dt);
    return true;
  });
  if(f==='past') list=list.slice().reverse();
  var h='<h2 style="margin-bottom:14px">Созвоны</h2>'+
  '<div class="metrics">'+
    metric(todayCnt,'Сегодня',todayCnt>0)+
    metric(tomorrowCnt,'Завтра')+
    metric(weekCnt,'На неделе')+
    metric(waitRep.length,'Ждут отчёта',waitRep.length>0)+
  '</div>';
  if(role==='manager'){
    h+='<button class="btn-ghost btn-sm" data-a="schedToggle" style="margin-bottom:14px">'+
      (S.schedOpen?'Скрыть моё расписание':'Моё расписание — открыть или посмотреть слоты')+'</button>';
    if(S.schedOpen) h+=vSchedManage();
  }
  h+='<div class="chips">'+
    chip('Сегодня','callsFilter','today',f)+
    chip('Завтра','callsFilter','tomorrow',f)+
    chip('Неделя','callsFilter','week',f)+
    chip('Все будущие','callsFilter','future',f)+
    chip('Ждут отчёта','callsFilter','rep',f)+
    chip('Прошедшие','callsFilter','past',f)+
    (role!=='admin'?'<button class="chip" data-a="callsMine" aria-pressed="'+S.callsMine+'">Только мои</button>':'')+
  '</div>'+
  '<input type="text" id="f-callsSearch" placeholder="Поиск по имени…" value="'+esc(S.callsSearch)+'">';
  if(!list.length) return h+'<div class="empty">'+
    (f==='today'?'На сегодня созвонов нет.<br>Посмотрите «Завтра» или «Все будущие».'
     :f==='rep'?'Все отчёты написаны — хорошая работа.'
     :'Здесь пока пусто.')+'</div>';
  var tgt=(S.mgr&&S.mgr.target)||5;
  var showMgr=!S.callsMine;   /* в своём дне колонка «Менеджер» не нужна */
  h+='<div class="card"><div class="scroll"><table><tr><th>Когда</th><th>Ученица</th><th>Телефон</th><th>Почта</th><th>Telegram</th>'+
    (showMgr?'<th>Менеджер</th>':'')+'<th>Ссылка</th><th>Статус</th><th></th></tr>';
  list.forEach(function(b){
    var no=(parseInt(b.call_no,10)||0)+1;
    h+='<tr><td style="white-space:nowrap">'+fmtKratko(b.dt)+
      '<div class="muted">созвон '+no+' из '+tgt+'</div></td>'+
      '<td><button class="btn-link" data-a="openCli" data-id="'+b.client_id+'">'+esc(b.client_name)+'</button>'+
      (b.course?'<div class="muted">'+esc(b.course)+'</div>':'')+
      (b.prev_next?'<div class="muted" style="margin-top:4px">К этой встрече: '+esc(b.prev_next)+
        (b.prev_next_dt?' (к '+fmtKratko(b.prev_next_dt)+')':'')+'</div>':'')+'</td>'+
      '<td class="mono">'+(b.c_phone?'<a href="tel:+'+esc(b.c_phone)+'">+'+esc(b.c_phone)+'</a>':'—')+'</td>'+
      '<td class="mono">'+(b.c_email?'<a href="mailto:'+esc(b.c_email)+'">'+esc(b.c_email)+'</a>':'—')+'</td>'+
      '<td class="mono">'+(b.c_tg?'<a href="https://t.me/'+esc(b.c_tg)+'" target="_blank" rel="noopener">@'+esc(b.c_tg)+'</a>':'—')+'</td>'+
      (showMgr?'<td>'+esc(b.manager_name)+'</td>':'')+
      '<td>'+(b.status==='booked'?(b.link?'<a href="'+esc(b.link)+'" target="_blank" rel="noopener">открыть</a>':'<span class="tag bad">нет</span>'):'—')+'</td>'+
      '<td>'+(STATUS_TAG[b.status]||esc(b.status))+(b.status==='held'&&b.rep_at?'<div class="muted">отчёт есть</div>':'')+'</td>'+
      '<td>'+callBtns(b)+'</td></tr>';
    if(S.repFor===b.id) h+='<tr><td colspan="'+(showMgr?9:8)+'">'+repForm(b)+'</td></tr>';
    if(S.moveFor===b.id) h+='<tr><td colspan="'+(showMgr?9:8)+'">'+moveForm(b)+'</td></tr>';
  });
  return h+'</table></div></div>';
}
function moveForm(b){
  if(!S.moveTimes) return '<div class="card" style="margin:0"><div class="empty">Загружаем свободное время…</div></div>';
  var times=(S.moveTimes||[]).filter(function(t){ return t.dt!==b.dt||t.manager_name!==b.manager_name; });
  var byDay={},order=[];
  times.forEach(function(t){ var d=String(t.dt).slice(0,10); if(!byDay[d]){byDay[d]=[];order.push(d);} byDay[d].push(t); });
  var h='<div class="card" style="margin:0"><h3>Переставить: '+esc(b.client_name)+', было '+fmtKratko(b.dt)+'</h3>';
  if(!order.length) h+='<p class="muted" style="margin-top:8px">Готового свободного времени сейчас нет — заведите время под неё ниже.</p>';
  else{
    h+='<div class="scroll" style="margin-top:12px">';
    order.forEach(function(d){
      h+='<div class="day"><div class="day-head"><b>'+esc(fmtDay(byDay[d][0].dt))+'</b></div><div class="tickets">';
      byDay[d].forEach(function(t){
        h+='<button class="ticket" data-a="staffMoveTo" data-id="'+b.id+'" data-slot="'+t.id+'">'+
           fmtTime(t.dt)+'<small>'+esc(t.manager_name)+'</small></button>';
      });
      h+='</div></div>';
    });
    h+='</div>';
  }
  var mgrs=S.moveMgrs||[];
  h+='<p style="margin-top:16px"><b>Или своё время</b> — заведём слот прямо на него</p>'+
    '<div class="row"><div><label>Дата и время</label><input type="datetime-local" id="f-moveDt"></div>'+
    (mgrs.length>1
      ?'<div><label>Кому</label><select id="f-moveMgr">'+
        mgrs.map(function(m){ return '<option value="'+m.id+'"'+(S.me&&m.id===S.me.id?' selected':'')+'>'+esc(m.name)+'</option>'; }).join('')+
       '</select></div>'
      :'')+'</div>'+
    '<button class="btn btn-sm'+(S.busy?' busy':'')+'" style="margin-top:10px" data-a="staffMoveCustom" data-id="'+b.id+'">Записать на это время</button> '+
    '<button class="btn-link" data-a="moveClose" style="margin-left:10px">Отменить перенос</button>'+
  '</div>';
  return h;
}
function callBtns(b){
  var h='';
  if(b.status==='booked'){
    /* пока встреча не началась, отмечать исход нечего — не путаем менеджера */
    if(isPast(b.dt))
      h+='<button class="btn-ghost btn-sm" data-a="mark" data-id="'+b.id+'" data-v="held">Провели</button> '+
         '<button class="btn-ghost btn-sm" data-a="mark" data-id="'+b.id+'" data-v="noshow">Не пришла</button> ';
    h+='<button class="btn-link" data-a="moveOpen" data-id="'+b.id+'">Переставить</button> '+
       '<button class="btn-link" data-a="markMove" data-id="'+b.id+'">Просить перенести</button> '+
       '<button class="btn-link" data-a="markCancel" data-id="'+b.id+'">Отмена</button>';
  }else if(b.status==='held'&&!b.rep_at){
    h+='<button class="btn btn-sm" data-a="repOpen" data-id="'+b.id+'">Написать отчёт</button>';
  }else if(b.status==='held'&&b.rep_at){
    h+='<button class="btn-link" data-a="repOpen" data-id="'+b.id+'">Поправить отчёт</button>';
  }
  return h;
}
function repForm(b){
  var r=S.rep;
  return '<div class="card" style="margin:0">'+
    '<h3>Отчёт: '+esc(b.client_name)+', '+fmtKratko(b.dt)+'</h3>'+
    '<label style="margin-top:12px">Как прошёл созвон, что обсудили (внутреннее — ученица не видит)</label>'+
    '<textarea id="rep-note" rows="5">'+esc(r.note)+'</textarea>'+
    '<label>Итоги для ученицы — увидит их в кабинете и получит сообщением</label>'+
    '<textarea id="rep-pub" rows="3" placeholder="К чему пришли, что делаем дальше — своими словами для неё">'+esc(r.pub||'')+'</textarea>'+
    '<label>Состояние ученицы одной строкой</label>'+
    '<input type="text" id="rep-status" value="'+esc(r.status)+'" placeholder="например: готова искать первых клиентов">'+
    '<div class="row"><div><label>Следующий шаг</label>'+
    '<input type="text" id="rep-next" value="'+esc(r.next)+'" placeholder="что делаем дальше"></div>'+
    '<div><label>К какой дате</label><input type="date" id="rep-nextdt" value="'+esc(r.next_dt)+'"></div></div>'+
    '<button class="btn'+(S.busy?' busy':'')+'" data-a="repSave" data-id="'+b.id+'">Сохранить отчёт</button> '+
    '<button class="btn-link" data-a="repClose" style="margin-left:10px">Свернуть</button>'+
  '</div>';
}

/* ---------- Моё расписание: встраивается в «Созвоны» у менеджера ---------- */
function vSchedManage(){
  var slots=((S.mgr&&S.mgr.slots)||[]).filter(function(s){ return !isPast(s.dt); });
  var h='<div class="card"><h3>Открыть время</h3>'+
    '<div class="row" style="margin-top:12px"><div><label>Дата</label>'+
    '<input type="date" id="f-draftDate" min="'+todayISO()+'" value="'+esc(S.draftDate)+'"></div></div>';
  if(S.draftDate){
    var times=[];
    for(var hh=8;hh<=21;hh++){
      times.push((hh<10?'0':'')+hh+':00');
      if(S.draftHalf) times.push((hh<10?'0':'')+hh+':30');
    }
    h+='<div class="chips">'+times.map(function(t){
      return '<button class="chip" data-a="draftTime" data-v="'+t+'" aria-pressed="'+(S.draftTimes.indexOf(t)>=0)+'">'+t+'</button>';
    }).join('')+'</div>'+
    '<button class="btn-link" data-a="draftHalf">'+(S.draftHalf?'Скрыть половинки':'Показать половинки часа')+'</button>'+
    '<label style="margin-top:14px">Одноразовая ссылка на созвон (не обязательно)</label>'+
    '<input type="text" id="f-draftLink" value="'+esc(S.draftLink)+'" placeholder="пусто — возьмётся постоянная из профиля">'+
    '<button class="btn'+(S.busy?' busy':'')+'" data-a="slotsAdd">Открыть '+(S.draftTimes.length||'')+'</button>';
  }
  h+='</div>';
  if(!slots.length) return h+'<div class="empty">Открытого времени впереди нет.</div>';
  var byDay={};
  slots.forEach(function(s){ var d=s.dt.slice(0,10); (byDay[d]=byDay[d]||[]).push(s); });
  h+='<div class="card">';
  Object.keys(byDay).sort().forEach(function(d){
    h+='<div class="day"><div class="day-head"><b>'+fmtDay(d+' 00:00')+'</b></div><div class="tickets">';
    byDay[d].forEach(function(s){
      var busyS=s.booking_id!==null;
      h+='<div class="ticket" style="cursor:default'+(busyS?';border-color:var(--accent)':'')+'">'+fmtTime(s.dt)+
        '<small>'+(busyS?'занят':'свободен')+(s.link?' · своя ссылка':'')+'</small>'+
        (busyS?'':'<small><button class="btn-link" data-a="slotDel" data-id="'+s.id+'">убрать</button> '+
          '<button class="btn-link" data-a="slotLink" data-id="'+s.id+'">ссылка</button></small>')+
      '</div>';
    });
    h+='</div></div>';
  });
  return h+'</div>';
}

function isoOf(d){ var p=function(n){return (n<10?'0':'')+n;}; return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); }

/* ---------- Ученицы ---------- */
function vClients(){
  if(!S.clients){ loadClients().then(render); return '<div class="empty">Загружаем…</div>'; }
  var list=S.clients.filter(function(c){
    if(S.cliStatus&&c.status!==S.cliStatus) return false;
    if(S.cliSearch&&hay(c.name,c.email,c.phone,c.tg,c.course).indexOf(S.cliSearch.toLowerCase())<0) return false;
    return true;
  });
  if(S.cliStatus==='late') list=(S.clients||[]).filter(function(c){
    if(String(c.stage||'').indexOf('Просрочено')!==0) return false;
    if(S.cliSearch&&hay(c.name,c.email,c.phone,c.tg,c.course).indexOf(S.cliSearch.toLowerCase())<0) return false;
    return true;
  });
  if(S.cliMineOnly&&S.me&&S.me.pair) list=list.filter(function(c){ return c.pair_name===S.me.pair; });
  var missingCnt=(S.clients||[]).filter(clientMissingData).length;
  var h='<h2 style="margin-bottom:14px">Ученицы</h2>'+
    '<div class="chips">'+
      chip('Все','cliStatus','',S.cliStatus)+
      chip('Новые','cliStatus','new',S.cliStatus)+
      chip('В работе','cliStatus','work',S.cliStatus)+
      chip('Просроченные','cliStatus','late',S.cliStatus)+
      chip('Завершён','cliStatus','done',S.cliStatus)+
      chip('Возвраты','cliStatus','refund',S.cliStatus)+
      (missingCnt?'<button class="chip" data-a="cliMissingOnly" aria-pressed="'+S.cliMissingOnly+'">Нет контактов ('+missingCnt+')</button>':'')+
      (S.me&&S.me.pair?'<button class="chip" data-a="cliMineOnly" aria-pressed="'+S.cliMineOnly+'">Моя пара «'+esc(S.me.pair)+'»</button>':'')+
    '</div>'+
    '<input type="text" id="f-cliSearch" placeholder="Поиск: имя, почта, телефон, курс…" value="'+esc(S.cliSearch)+'">'+
    '<p class="muted">'+list.length+' из '+S.clients.length+'</p>';
  if(S.cliMissingOnly) list=list.filter(clientMissingData);
  if(!list.length) return h+'<div class="empty">Никого не нашлось.</div>';
  h+='<div class="card"><div class="scroll"><table><tr><th>Ученица</th><th>Курс</th><th>Пара</th><th>Статус</th><th>Стадия</th><th>Ближайший созвон</th><th class="num">Проведено</th><th class="num">Неявки / переносы</th></tr>';
  list.forEach(function(c){
    h+='<tr><td><button class="btn-link" data-a="openCli" data-id="'+c.id+'">'+esc(c.name)+'</button>'+
      (clientMissingData(c)?' <span class="tag bad">нет контактов</span>':'')+'</td>'+
      '<td>'+esc(c.course||'—')+'</td>'+
      '<td>'+(c.pair_name?esc(c.pair_name):'<span class="muted">—</span>')+'</td>'+
      '<td>'+(CLI_TAG[c.status]||'')+((+c.refused)?' <span class="tag bad">отказ</span>':'')+'</td>'+
      '<td>'+stageTag(c.stage)+'</td>'+
      '<td>'+(c.next_dt?fmtKratko(c.next_dt):(c.last_held?'<span class="muted">был '+fmtKratko(c.last_held)+'</span>':'<span class="muted">нет</span>'))+'</td>'+
      '<td class="num">'+(c.held_cnt||0)+'</td>'+
      '<td class="num">'+(c.noshow_cnt||0)+' / '+(c.moved_cnt||0)+'</td></tr>';
  });
  return h+'</table></div></div>';
}
/* Незаполненные данные у новой ученицы — нечем связаться (заказчица процесса, 29.08) */
function clientMissingData(c){ return c.status==='new' && !c.phone && !c.tg; }

/* ---------- Карточка ученицы ---------- */
function vCard(){
  var j=S.card;
  if(!j) return '<div class="empty">Загружаем…</div>';
  var c=j.client;
  var h='<button class="btn-link" data-a="closeCli">← Ко всем ученицам</button>'+
    '<div class="split" style="margin:10px 0 14px"><h2>'+
    (c.photo?'<img class="avatar avatar-sm" src="'+esc('foto/'+c.photo)+'" alt=""> ':'')+
    esc(c.name)+' '+(CLI_TAG[c.status]||'')+'</h2><div>'+
    (c.status!=='done'
      ?'<button class="'+((j.held||0)>=((S.mgr&&S.mgr.target)||5)?'btn':'btn-ghost btn-sm')+(S.busy?' busy':'')+'" data-a="cliDone" data-id="'+c.id+'">Онбординг завершён</button>'
      :'<button class="btn-ghost" data-a="cliWork" data-id="'+c.id+'">Вернуть в работу</button>')+
    '</div></div>';
  if(c.status==='done'&&c.done_at) h+='<p class="muted">Завершён '+fmtKratko(c.done_at)+' — '+esc(c.done_by)+'</p>';
  var dneY=Math.floor((Date.now()-dt(c.created_at).getTime())/86400000);
  var vSist=dneY<1?'сегодня':(dneY===1?'вчера':dneY+' '+plural(dneY,['день','дня','дней'])+' назад');
  h+='<p class="muted">Появилась '+vSist+' · проведено '+(j.held||0)+' из '+((S.mgr&&S.mgr.target)||5)+' · график: день 0–3–5–7–10 · '+stageTag(j.stage)+
    ((+c.refused)?' · <span class="tag bad">отказалась от созвонов</span>':'')+'</p>';
  if(c.status==='refund') h+='<div class="note warn">Возврат'+(c.refund_reason?': '+esc(c.refund_reason):'')+
    (c.refund_at?' ('+fmtKratko(c.refund_at)+')':'')+'. Запись на созвоны закрыта. '+
    '<button class="btn-link" data-a="cliStatus" data-id="'+c.id+'" data-v="work">Вернуть в работу</button></div>';
  if(j.missing) h+='<div class="note warn">Нет ни телефона, ни Telegram — нечем связаться. Заполните контакты ниже.</div>';
  var pairs=j.pairs||[];
  h+='<div class="card"><h3>Закреплённая пара менеджеров</h3>'+
    '<p class="muted" style="margin:8px 0 12px">Кто ведёт её до выпуска. Список пар настраивается владельцем в разделе «Менеджеры».</p>'+
    (pairs.length
      ?'<select id="f-clipair">'+
        '<option value=""'+(!c.pair_name?' selected':'')+'>— не закреплена —</option>'+
        pairs.map(function(p){ return '<option value="'+esc(p)+'"'+(c.pair_name===p?' selected':'')+'>'+esc(p)+'</option>'; }).join('')+
       '</select>'+
       '<button class="btn-ghost btn-sm'+(S.busy?' busy':'')+'" data-a="cliPairSave" data-id="'+c.id+'">Сохранить</button>'
      :'<p class="muted">Пар пока нет — владелец ещё не объединил менеджеров.</p>')+
  '</div>';
  h+='<div class="card"><h3>Контакты и курс</h3><div class="scroll"><table style="margin-top:10px">'+
    '<tr><td class="muted" style="width:150px">Почта</td><td class="mono">'+esc(c.email||'—')+'</td></tr>'+
    '<tr><td class="muted">Телефон</td><td class="mono">'+(c.phone?'<a href="tel:+'+esc(c.phone)+'">+'+esc(c.phone)+'</a>':'—')+'</td></tr>'+
    '<tr><td class="muted">Telegram</td><td class="mono">'+(c.tg?'<a href="https://t.me/'+esc(c.tg)+'" target="_blank" rel="noopener">@'+esc(c.tg)+'</a>':'—')+'</td></tr>'+
    (c.contact?'<tr><td class="muted">Связь</td><td class="mono">'+esc(c.contact)+'</td></tr>':'')+
    '<tr><td class="muted">Курс</td><td>'+esc(c.course||'—')+'</td></tr>'+
    '<tr><td class="muted">В списке с</td><td>'+fmtKratko(c.created_at)+'</td></tr>'+
    '<tr><td class="muted">Неявки / переносы</td><td>'+(c.noshow_cnt||0)+' / '+(c.moved_cnt||0)+'</td></tr>'+
    '</table></div>'+
    '<div style="margin-top:8px">'+
    ((+c.refused)
      ?'<button class="btn-ghost btn-sm" data-a="cliRefuse" data-id="'+c.id+'" data-v="0">Вернулась к созвонам</button> '
      :'<button class="btn-ghost btn-sm" data-a="cliRefuse" data-id="'+c.id+'" data-v="1">Отказалась от созвонов</button> ')+
    '<button class="btn-ghost btn-sm" data-a="cliInvite" data-id="'+c.id+'">Пригласить письмом</button> '+
    '<button class="btn-ghost btn-sm" data-a="copyUrl">Скопировать личную ссылку</button> '+
    (c.status!=='done'&&S.me&&S.me.role!=='admin'?'<button class="btn-ghost btn-sm" data-a="cardBook">Записать к себе</button>':'')+
    '</div>'+
    (S.cardBook?cardBookBlock(c):'')+
  '</div>';
  h+='<div class="card"><div class="split" style="align-items:center"><h3>Учёт: уроки, бонус, ссылки</h3>'+
    '<button class="btn-link" data-a="cardFieldsToggle">'+(S.cardFields?'свернуть':'открыть')+'</button></div>'+
    (S.cardFields?'':'<p class="muted" style="margin:8px 0 0">Старт цикла, просмотренные уроки, подарочный курс, ссылки на Омнидеск и сделку.</p>')+
    (S.cardFields?'<div class="row" style="margin-top:12px">':'<div style="display:none"><div class="row">')+
    '<div><label>Старт цикла (10 дней)</label><input type="date" id="f-startdt" value="'+esc(c.start_dt||'')+'">'+
    '<div class="field-hint">Ставится сам при первом проведённом созвоне</div></div>'+
    '<div><label>Бонус — мини-курс в подарок</label><input type="text" id="f-bonus" value="'+esc(c.bonus||'')+'" placeholder="какой курс выбрала"></div></div>'+
    '<div class="row">'+
    '<div><label>Уроков на старте</label><input type="number" id="f-lstart" value="'+esc(c.lessons_start==null?'':c.lessons_start)+'"></div>'+
    '<div><label>На 5-й день</label><input type="number" id="f-l5" value="'+esc(c.lessons_d5==null?'':c.lessons_d5)+'"></div>'+
    '<div><label>На завершение</label><input type="number" id="f-lend" value="'+esc(c.lessons_end==null?'':c.lessons_end)+'">'+
    (c.lessons_start!=null&&c.lessons_end!=null?'<div class="field-hint">Прирост: '+(c.lessons_end-c.lessons_start)+'</div>':'')+'</div></div>'+
    '<div class="row">'+
    '<div><label>Омнидеск</label><input type="text" id="f-omni" value="'+esc(c.omni_url||'')+'" placeholder="https://…"></div>'+
    '<div><label>Сделка (Битрикс)</label><input type="text" id="f-deal" value="'+esc(c.deal_url||'')+'" placeholder="https://…"></div></div>'+
    ((c.omni_url?'<a href="'+esc(c.omni_url)+'" target="_blank" rel="noopener" class="btn-link">открыть Омнидеск</a> ':'')+
     (c.deal_url?'<a href="'+esc(c.deal_url)+'" target="_blank" rel="noopener" class="btn-link">открыть сделку</a>':''))+
    '<div style="margin-top:12px"><button class="btn-ghost btn-sm'+(S.busy?' busy':'')+'" data-a="fieldsSave" data-id="'+c.id+'">Сохранить</button> '+
    (c.status!=='refund'?'<button class="btn-link" data-a="cliRefund" data-id="'+c.id+'">Возврат…</button> ':'')+
    (c.status!=='frozen'&&c.status!=='refund'&&c.status!=='done'?'<button class="btn-link" data-a="cliStatus" data-id="'+c.id+'" data-v="frozen">Заморозка / старт позже</button> ':'')+
    (c.status==='frozen'?'<button class="btn-link" data-a="cliStatus" data-id="'+c.id+'" data-v="work">Вернуть в работу</button>':'')+
    '</div>'+(S.cardFields?'':'</div>')+'</div>';
  h+='<div class="card"><h3>Заметка</h3>'+
    '<textarea id="f-clinote" rows="3" placeholder="Что важно помнить об этой ученице">'+esc(c.note||'')+'</textarea>'+
    '<button class="btn-ghost btn-sm'+(S.busy?' busy':'')+'" data-a="noteSave" data-id="'+c.id+'">Сохранить заметку</button></div>';
  h+='<div class="card"><h3>Анамнез — созвоны и отчёты</h3>';
  var bs=j.bookings||[];
  if(!bs.length) h+='<div class="empty">Созвонов ещё не было.</div>';
  else{
    h+='<div class="feed" style="margin-top:14px">';
    bs.forEach(function(b){
      h+='<div class="feed-item'+(b.rep_at?' rep':'')+'">'+
        '<div class="when">'+fmtKratko(b.dt)+' · '+esc(b.manager_name)+' · '+(STATUS_TAG[b.status]||'')+'</div>';
      if(b.rep_at){
        h+='<div class="rep-note">'+esc(b.rep_note||'')+
          (b.rep_status?'\n\nСостояние: '+esc(b.rep_status):'')+
          (b.rep_next?'\nСледующий шаг: '+esc(b.rep_next)+(b.rep_next_dt?' (к '+fmtKratko(b.rep_next_dt)+')':''):'')+
        '</div>';
      }else if(b.status==='held'){
        h+='<div class="muted">отчёта пока нет</div>';
      }
      h+='</div>';
    });
    h+='</div>';
  }
  h+='</div>';
  var hist=(S.me&&S.me.role==='manager')?[]:(j.history||[]);
  if(hist.length){
    h+='<div class="card"><h3>Лента событий</h3><div class="feed" style="margin-top:14px">';
    hist.forEach(function(e){
      h+='<div class="feed-item"><div class="when">'+fmtKratko(e.at)+'</div>'+esc(e.txt)+'</div>';
    });
    h+='</div></div>';
  }
  return h;
}
function cardBookBlock(c){
  var free=((S.mgr&&S.mgr.slots)||[]).filter(function(s){ return s.booking_id===null&&!isPast(s.dt); });
  if(!free.length) return '<div class="note" style="margin-top:12px">У вас нет свободных слотов впереди — откройте время в «Расписании».</div>';
  return '<div style="margin-top:12px"><p class="muted">Выберите свой слот — ученица получит подтверждение и напоминания:</p><div class="tickets">'+
    free.map(function(s){
      return '<button class="ticket" data-a="bookClient" data-id="'+s.id+'" data-cli="'+c.id+'">'+fmtTime(s.dt)+
        '<small>'+fmtKratko(s.dt).split(',')[0]+'</small></button>';
    }).join('')+'</div></div>';
}

/* ---------- Менеджеры глазами куратора: слоты и записи ---------- */
function vManagers(){
  if(!S.mgrs){
    api('managers_view').then(function(j){ S.mgrs=j.managers; render(); }).catch(function(e){ toast(e.message); });
    return '<div class="empty">Загружаем…</div>';
  }
  var list=S.mgrs;
  var h='<h2 style="margin-bottom:14px">Менеджеры</h2>';
  if(!list.length) return h+'<div class="empty">Активных менеджеров пока нет.</div>';
  var totalFree=0,totalBusy=0,totalDebt=0;
  list.forEach(function(m){ totalFree+=m.free; totalBusy+=m.busy; totalDebt+=m.debt; });
  h+='<div class="metrics">'+
    metric(list.length,'менеджеров')+
    metric(totalBusy,'записей впереди')+
    metric(totalFree,'свободных слотов')+
    metric(totalDebt,'долг по отчётам',totalDebt>0)+
  '</div>';
  h+='<div class="card"><div class="scroll"><table><tr><th>Менеджер</th><th>Пара</th><th class="num">Свободно</th><th class="num">Записей</th><th class="num">Проведено</th><th class="num">Неявки</th><th class="num">Без отчёта</th><th></th></tr>';
  list.forEach(function(m){
    h+='<tr><td>'+esc(m.name)+
      (m.tgOn?' <span class="tag ok">TG</span>':' <span class="tag wait">нет TG</span>')+
      (m.hasLink?'':'<div class="muted">не задана ссылка на комнату</div>')+'</td>'+
      '<td>'+(m.pair?esc(m.pair):'<span class="muted">—</span>')+'</td>'+
      '<td class="num">'+m.free+(m.free===0?' <span class="tag bad">нет</span>':'')+'</td>'+
      '<td class="num">'+m.busy+'</td>'+
      '<td class="num">'+m.held+'</td>'+
      '<td class="num">'+m.noshow+'</td>'+
      '<td class="num">'+(m.debt?'<span class="tag bad">'+m.debt+'</span>':'0')+'</td>'+
      '<td><button class="btn-link" data-a="mgrOpen" data-id="'+m.id+'">'+(S.mgrOpen===m.id?'скрыть':'расписание')+'</button></td></tr>';
    if(S.mgrOpen===m.id) h+='<tr><td colspan="8">'+mgrSchedule(m)+'</td></tr>';
  });
  return h+'</table></div></div>';
}
function mgrSchedule(m){
  var slots=m.slots||[];
  if(!slots.length) return '<div class="empty">Открытого времени впереди нет.</div>';
  var byDay={},order=[];
  slots.forEach(function(s){ var d=s.dt.slice(0,10); if(!byDay[d]){byDay[d]=[];order.push(d);} byDay[d].push(s); });
  var h='<div class="card" style="margin:0">';
  order.forEach(function(d){
    h+='<div class="day"><div class="day-head"><b>'+fmtDay(d+' 00:00')+'</b></div><div class="tickets">';
    byDay[d].forEach(function(s){
      var busy=s.booking_id!==null;
      h+='<div class="ticket" style="cursor:default'+(busy?';border-color:var(--accent)':'')+'">'+fmtTime(s.dt)+
        '<small>'+(busy?esc(s.client_name||'занят'):'свободен')+'</small></div>';
    });
    h+='</div></div>';
  });
  return h+'</div>';
}

/* ---------- Команда: доступы и роли (владелец) ---------- */
function vPeople(){
  if(!S.users){ api('users').then(function(j){ S.users=j.users; render(); }).catch(function(e){ toast(e.message); }); return '<div class="empty">Загружаем…</div>'; }
  /* Ученицы регистрируются через ту же дверь, что и команда, поэтому их
     аккаунты лежат в той же таблице. Но в разделе про команду им не место:
     ими управляют во вкладке «Ученицы». Держим отдельно и под катом. */
  var STAFF={manager:1,admin:1,owner:1};
  var pend=S.users.filter(function(u){ return u.status==='pending'; });
  var rest=S.users.filter(function(u){ return u.status!=='pending'&&STAFF[u.role]; });
  var pupils=S.users.filter(function(u){ return u.status!=='pending'&&!STAFF[u.role]; });
  var h='<h2 style="margin-bottom:14px">Команда</h2>';
  var managers=S.users.filter(function(u){ return u.role==='manager'&&u.status==='active'; });
  var pairs={};
  managers.forEach(function(u){ if(u.pair_name) (pairs[u.pair_name]=pairs[u.pair_name]||[]).push(u.name); });
  h+='<div class="card"><h3>Пары (график 2/2, ведут ученицу до выпуска)</h3>'+
    '<p class="muted" style="margin:8px 0 12px">Впишите одинаковую метку двум менеджерам в таблице ниже — они объединятся в пару. Дальше в карточке ученицы куратор закрепляет за ней конкретную пару.</p>';
  if(Object.keys(pairs).length){
    h+='<div class="scroll"><table style="margin-bottom:0"><tr><th>Пара</th><th>Кто в паре</th></tr>';
    Object.keys(pairs).sort().forEach(function(p){
      h+='<tr><td><b>'+esc(p)+'</b></td><td>'+pairs[p].map(esc).join(', ')+
        (pairs[p].length===1?' <span class="tag wait">одна — добавьте вторую</span>':'')+'</td></tr>';
    });
    h+='</table></div>';
  }else h+='<div class="empty">Пар пока нет.</div>';
  h+='</div>';
  if(pend.length){
    h+='<div class="card"><h3>Ждут подтверждения</h3><div class="scroll"><table style="margin-top:10px"><tr><th>Кто</th><th>Контакты</th><th></th></tr>';
    pend.forEach(function(u){
      var prosit={manager:'менеджер',admin:'куратор',owner:'владелец',client:'ученица'}[u.role]||u.role;
      h+='<tr><td>'+esc(u.name)+'<div class="muted">просится как '+esc(prosit)+' · '+fmtKratko(u.created_at)+'</div></td>'+
        '<td class="mono">'+esc(u.email)+(u.phone?'<br>+'+esc(u.phone):'')+(u.tg?'<br>@'+esc(u.tg):'')+'</td>'+
        '<td><button class="btn btn-sm'+(S.busy?' busy':'')+'" data-a="userSet" data-id="'+u.id+'" data-v="approve">Открыть доступ</button> '+
        '<button class="btn-link" data-a="userSet" data-id="'+u.id+'" data-v="reject">Отклонить</button></td></tr>';
    });
    h+='</table></div></div>';
  }
  h+='<div class="card"><h3>Менеджеры, кураторы, владельцы</h3><div class="scroll"><table style="margin-top:10px"><tr><th>Кто</th><th>Роль</th><th>Контакты</th><th>Пара</th><th>Был(а)</th><th></th></tr>';
  rest.forEach(function(u){
    var roleName=u.role==='owner'?'<b>владелец</b>':u.role==='manager'?'менеджер':'куратор';
    h+='<tr><td>'+esc(u.name)+(u.status==='rejected'?' <span class="tag bad">закрыт</span>':'')+'</td>'+
      '<td>'+roleName+(u.tg_chat?' <span class="tag ok">TG</span>':'')+'</td>'+
      '<td class="mono">'+esc(u.email)+(u.phone?'<br>+'+esc(u.phone):'')+'</td>'+
      '<td>'+(u.role==='manager'&&u.status==='active'
        ?'<input type="text" id="f-pair-'+u.id+'" value="'+esc(u.pair_name||'')+'" placeholder="напр. Пара 1" style="margin:0;min-width:110px">'+
         '<button class="btn-link" data-a="pairSave" data-id="'+u.id+'">сохранить</button>'
        :'<span class="muted">—</span>')+'</td>'+
      '<td>'+(u.last_at?fmtKratko(u.last_at):'—')+'</td>'+
      '<td>'+(u.status==='rejected'
        ?'<button class="btn-ghost btn-sm" data-a="userSet" data-id="'+u.id+'" data-v="approve">Открыть снова</button>'
        :roleBtns(u))+
      '</td></tr>';
  });
  h+='</table></div></div>';

  /* Аккаунты учениц — отдельно и свёрнуто: нужны редко, для разбора
     «не могу войти», а не для управления командой. */
  h+='<div class="card"><div class="split" style="align-items:center">'+
    '<h3>Аккаунты учениц ('+pupils.length+')</h3>'+
    '<button class="btn-link" data-a="pupilsToggle">'+(S.pupilsOpen?'свернуть':'показать')+'</button></div>'+
    '<p class="muted" style="margin:8px 0 0">Ученицы заводят их сами при регистрации. Работа с ученицами — во вкладке «Ученицы»; здесь только доступ ко входу.</p>';
  if(S.pupilsOpen){
    if(!pupils.length) h+='<div class="empty">Пока ни одна ученица не регистрировалась.</div>';
    else{
      h+='<input type="text" id="f-pupilSearch" placeholder="Поиск по имени или почте…" value="'+esc(S.pupilSearch||'')+'">';
      var pl=pupils.filter(function(u){
        return !S.pupilSearch||hay(u.name,u.email).indexOf(S.pupilSearch.toLowerCase())>=0;
      });
      h+='<div class="scroll"><table style="margin-top:10px"><tr><th>Кто</th><th>Почта</th><th>Был(а)</th><th></th></tr>';
      pl.slice(0,100).forEach(function(u){
        h+='<tr><td>'+esc(u.name)+(u.status==='rejected'?' <span class="tag bad">закрыт</span>':'')+'</td>'+
          '<td class="mono">'+esc(u.email)+'</td>'+
          '<td>'+(u.last_at?fmtKratko(u.last_at):'<span class="muted">не заходила</span>')+'</td>'+
          '<td>'+(u.status==='rejected'
            ?'<button class="btn-link" data-a="userSet" data-id="'+u.id+'" data-v="approve">Открыть снова</button>'
            :'<button class="btn-link" data-a="userReject" data-id="'+u.id+'">Закрыть доступ</button>')+'</td></tr>';
      });
      h+='</table></div>';
      if(pl.length>100) h+='<div class="field-hint" style="margin-top:10px">Показаны первые 100 — уточните поиск.</div>';
    }
  }
  return h+'</div>';
}

function roleBtns(u){
  /* Ошиблась при регистрации — владелец переводит в нужную роль одним кликом */
  var names={owner:'владельцем',admin:'куратором',manager:'менеджером'};
  var h='';
  ['admin','manager','owner'].forEach(function(r){
    if(u.role!==r) h+='<button class="btn-link" data-a="userSet" data-id="'+u.id+'" data-v="'+r+'">Сделать '+names[r]+'</button> ';
  });
  if(u.role!=='owner') h+='<button class="btn-link" data-a="userReject" data-id="'+u.id+'">Закрыть доступ</button>';
  return h;
}

/* ---------- Статистика (владелец) ---------- */
function blockOn(key){
  var b=(S.stats&&S.stats.blocksOn)||'';
  return !b || b.split(',').indexOf(key)>=0;
}
/* Строка сравнения «было/стало» — как в отчётах заказчицы процесса */
function cmpRow(label,prev,cur,isNum){
  isNum=isNum!==false;
  var p=prev==null?'—':prev, c=cur==null?'—':cur;
  var d='—';
  if(isNum&&prev!=null&&cur!=null){
    var delta=Math.round((cur-prev)*10)/10;
    d=(delta>0?'+':'')+delta;
  }
  return '<tr><td>'+esc(label)+'</td><td class="num">'+p+'</td><td class="num">'+c+'</td><td class="num">'+d+'</td></tr>';
}
function vStats(){
  if(!S.stats){
    var body={};
    if(S.sFrom) body.from=S.sFrom;
    if(S.sTo) body.to=S.sTo;
    api('stats',body).then(function(j){ S.stats=j; if(S.statsCompare) loadStatsPrev(); render(); }).catch(function(e){ toast(e.message); });
    return '<div class="empty">Считаем…</div>';
  }
  var st=S.stats, bs=st.byStatus||{};
  var h='<h2 style="margin-bottom:14px">Сводная</h2>'+
    '<div class="chips">'+
      '<button class="chip" data-a="statsWeek" data-v="0">Отчётная неделя (чт–ср)</button>'+
      '<button class="chip" data-a="statsWeek" data-v="1">Прошлая (чт–ср)</button>'+
      '<button class="chip" data-a="statsWeek" data-v="30">30 дней</button>'+
      '<button class="chip" data-a="statsWeek" data-v="month">Месяц</button>'+
      '<button class="chip" data-a="statsWeek" data-v="all">Всё время</button>'+
    '</div>'+
    '<div class="row"><div><label>С</label><input type="date" id="f-sFrom" value="'+esc(S.sFrom)+'"></div>'+
    '<div><label>По</label><input type="date" id="f-sTo" value="'+esc(S.sTo)+'"></div>'+
    '<div style="align-self:end"><button class="btn-ghost" data-a="statsGo" style="margin-bottom:14px">Показать</button></div></div>'+
    '<p class="muted">Период: '+esc(st.from||'')+' — '+esc(st.to||'')+'</p>';
  if(blockOn('top'))
    h+='<div class="metrics">'+
      metric(st.total||0,'всего учениц')+
      metric(bs['work']||0,'в работе',true)+
      metric(bs['new']||0,'новых')+
      metric(bs['refund']||0,'возвратов всего')+
      metric(bs['done']||0,'завершили')+
      metric(bs['frozen']||0,'заморозка')+
    '</div>';
  if(blockOn('period'))
    h+='<div class="card"><h3>За период</h3><div class="metrics" style="margin-top:12px">'+
      metric(st.newInPeriod||0,'новых учениц')+
      metric(st.heldInPeriod||0,'созвонов проведено')+
      metric(st.refundInPeriod||0,'возвратов',st.refundInPeriod>0)+
      metric(st.doneInPeriod||0,'завершили онбординг')+
      metric(st.moved||0,'переносов')+
      metric(st.avgDays===null?'—':st.avgDays,'дней до 1-го созвона')+
    '</div></div>';
  if(blockOn('compare')){
    h+='<div class="card"><h3>Сравнение с предыдущим периодом</h3>'+
      '<label style="font-weight:400;display:flex;gap:8px;align-items:center;margin:10px 0 0"><input type="checkbox" id="f-statscmp" style="width:auto;margin:0" data-a="statsCompareToggle"'+(S.statsCompare?' checked':'')+'> Сравнить с равным по длине периодом, который шёл непосредственно перед этим</label>';
    if(S.statsCompare){
      if(!S.statsPrev) h+='<div class="empty">Считаем…</div>';
      else{
        var pv=S.statsPrev;
        h+='<p class="muted" style="margin-top:10px">Предыдущий период: '+esc(pv.from)+' — '+esc(pv.to)+'</p>'+
        '<div class="scroll"><table style="margin-top:6px"><tr><th>Показатель</th><th class="num">Было</th><th class="num">Стало</th><th class="num">Δ</th></tr>'+
          cmpRow('Новых учениц',pv.newInPeriod,st.newInPeriod)+
          cmpRow('Созвонов проведено',pv.heldInPeriod,st.heldInPeriod)+
          cmpRow('Возвратов',pv.refundInPeriod,st.refundInPeriod)+
          cmpRow('Завершили онбординг',pv.doneInPeriod,st.doneInPeriod)+
          cmpRow('Переносов',pv.moved,st.moved)+
          cmpRow('Дней до 1-го созвона',pv.avgDays,st.avgDays)+
          cmpRow('Прирост уроков',pv.lessonsGrowth,st.lessonsGrowth)+
        '</table></div>';
        var pm={}; (pv.byManager||[]).forEach(function(r){ pm[r.name]=r; });
        var names={}; (pv.byManager||[]).concat(st.byManager||[]).forEach(function(r){ names[r.name]=1; });
        if(Object.keys(names).length){
          h+='<div class="scroll" style="margin-top:14px"><table><tr><th>Менеджер</th><th class="num">Проведено было</th><th class="num">стало</th><th class="num">Δ</th><th class="num">Неявки было</th><th class="num">стало</th><th class="num">Δ</th></tr>';
          Object.keys(names).sort().forEach(function(nm){
            var p=pm[nm]||{held:0,noshow:0}, c=(st.byManager||[]).filter(function(r){return r.name===nm;})[0]||{held:0,noshow:0};
            h+='<tr><td>'+esc(nm)+'</td><td class="num">'+(p.held||0)+'</td><td class="num">'+(c.held||0)+'</td><td class="num">'+((c.held||0)-(p.held||0))+'</td>'+
              '<td class="num">'+(p.noshow||0)+'</td><td class="num">'+(c.noshow||0)+'</td><td class="num">'+((c.noshow||0)-(p.noshow||0))+'</td></tr>';
          });
          h+='</table></div>';
        }
      }
    }
    h+='</div>';
  }
  if(blockOn('lessons'))
    h+='<div class="card"><h3>Уроки</h3><div class="metrics" style="margin-top:12px">'+
      metric(st.lessonsViewed||0,'просмотрено уроков (всего)')+
      metric(st.lessonsGrowth===null||st.lessonsGrowth===undefined?'—':st.lessonsGrowth,'прирост уроков')+
    '</div></div>';
  var tch=st.touches||{};
  if(blockOn('touches')&&Object.keys(tch).length){
    h+='<div class="card"><h3>Проведено касаний (без возвратов)</h3><div class="scroll"><table style="margin-top:10px">'+
      '<tr><th>Касание</th><th class="num">Учениц с этим касанием</th></tr>';
    Object.keys(tch).forEach(function(n){
      h+='<tr><td>Касание '+n+' (день '+({1:'0–1',2:'3',3:'5',4:'7',5:'10'}[n]||n)+')</td><td class="num">'+tch[n]+'</td></tr>';
    });
    h+='</table></div></div>';
  }
  if(blockOn('stale'))
    h+='<div class="metrics">'+
      metric(st.stale,'без записи '+st.staleDays+'+ дн.',st.stale>0)+
      metric(st.refused||0,'отказались от созвонов')+
    '</div>';
  var stg=st.stages||{};
  if(blockOn('stages')&&Object.keys(stg).length){
    h+='<div class="card"><h3>Сопровождение — 10-дневный цикл</h3><div class="scroll"><table style="margin-top:10px">';
    Object.keys(stg).sort().forEach(function(k){
      h+='<tr><td>'+esc(k)+'</td><td class="num">'+stg[k]+'</td></tr>';
    });
    h+='</table></div></div>';
  }
  var bc=st.byCourse||[];
  if(blockOn('course')&&bc.length){
    h+='<div class="card"><h3>По курсу</h3><div class="scroll"><table style="margin-top:10px">';
    bc.forEach(function(r){ h+='<tr><td>'+esc(r.course)+'</td><td class="num">'+r.c+'</td></tr>'; });
    h+='</table></div></div>';
  }
  var br=st.byReason||[];
  if(blockOn('reason')&&br.length){
    h+='<div class="card"><h3>По причине возврата</h3><div class="scroll"><table style="margin-top:10px">';
    br.forEach(function(r){ h+='<tr><td>'+esc(r.reason||'без причины')+'</td><td class="num">'+r.c+'</td></tr>'; });
    h+='</table></div></div>';
  }
  var bm=st.byManager||[];
  if(blockOn('managers')){
    if(bm.length){
      h+='<div class="card"><h3>По менеджерам</h3><div class="scroll"><table style="margin-top:10px">'+
        '<tr><th>Менеджер</th><th class="num">Проведено</th><th class="num">Неявки</th><th class="num">Впереди</th></tr>';
      bm.forEach(function(r){
        h+='<tr><td>'+esc(r.name)+'</td><td class="num">'+(r.held||0)+'</td><td class="num">'+(r.noshow||0)+
          '</td><td class="num">'+(r.planned||0)+'</td></tr>';
      });
      h+='</table></div></div>';
    }else h+='<div class="empty">За период созвонов не было.</div>';
  }
  return h;
}
/* Предыдущий период — та же длина, сразу перед текущим (для «было/стало») */
function loadStatsPrev(){
  var st=S.stats; if(!st) return Promise.resolve();
  var f=dt(st.from+' 00:00'), t=dt(st.to+' 00:00');
  var lenDays=Math.round((t-f)/86400000)+1;
  var prevTo=new Date(f.getTime()-86400000);
  var prevFrom=new Date(prevTo.getTime()-(lenDays-1)*86400000);
  return api('stats',{from:isoOf(prevFrom),to:isoOf(prevTo)}).then(function(j){ S.statsPrev=j; render(); }).catch(function(e){ toast(e.message); });
}

/* ---------- Настройки (владелец) ---------- */
function vSettings(){
  if(!S.settings){
    api('settings_get').then(function(j){ S.settings=j.settings; S.statsBlocksDict=j.statsBlocks; render(); }).catch(function(e){ toast(e.message); });
    return '<div class="empty">Загружаем…</div>';
  }
  var s=S.settings;
  return '<h2 style="margin-bottom:14px">Настройки</h2>'+
  '<div class="card"><h3>Импорт учениц из школы</h3>'+
    '<p class="muted" style="margin:8px 0 12px">Список читается из школы (только чтение), новые появляются со статусом «новая». '+
    (s.last_import?'Последний импорт: '+fmtKratko(s.last_import)+'.':'Импорт ещё не запускали.')+'</p>'+
    '<label><input type="checkbox" id="f-autoimport"'+(s.auto_import==='1'?' checked':'')+' style="width:auto;margin-right:8px">Подтягивать новых автоматически, раз в сутки</label>'+
    '<label style="margin-top:12px">Брать из школы тех, кто появился там начиная с</label>'+
    '<input type="date" id="f-importfrom" value="'+esc(s.import_from||'')+'">'+
    '<div class="field-hint">В школе лежит весь накопленный список. Эта дата отсекает старых: подтянутся только те, кто пришёл в школу с указанного дня. Пусто — берём всех.</div>'+
    '<label style="margin-top:12px">Какие курсы берём в онбординг (по одному на строку; пусто — все)</label>'+
    '<textarea id="f-courses" rows="4" placeholder="Например: Профессия коуч">'+esc(s.courses_allow)+'</textarea>'+
    '<button class="btn-ghost'+(S.busy?' busy':'')+'" data-a="importNow">Обновить список сейчас</button>'+
  '</div>'+
  '<div class="card"><h3>Созвоны и напоминания</h3>'+
    '<div class="row"><div><label>Длительность созвона, минут</label><input type="number" id="f-duration" value="'+esc(s.duration)+'"></div>'+
    '<div><label>«Зависла» после, дней</label><input type="number" id="f-staledays" value="'+esc(s.stale_days)+'"></div></div>'+
    '<label><input type="checkbox" id="f-remind24"'+(s.remind24==='1'?' checked':'')+' style="width:auto;margin-right:8px">Напоминать за сутки</label>'+
    '<label><input type="checkbox" id="f-remind2"'+(s.remind2==='1'?' checked':'')+' style="width:auto;margin-right:8px">Напоминать за два часа</label>'+
    '<label style="margin-top:12px">План онбординга, созвонов на ученицу</label>'+
    '<input type="number" id="f-target" value="'+esc(s.target_calls||'5')+'">'+
    '<label>Текст ученице после завершения (сертификат, подарок)</label>'+
    '<textarea id="f-certnote" rows="3">'+esc(s.cert_note||'')+'</textarea>'+
  '</div>'+
  '<div class="card"><h3>Общее</h3>'+
    '<label>Название</label><input type="text" id="f-org" value="'+esc(s.org)+'">'+
    '<label>Почта владельца для тревог и заявок</label><input type="email" id="f-alertemail" value="'+esc(s.alert_email)+'">'+
    '<button class="btn'+(S.busy?' busy':'')+'" data-a="settingsSave">Сохранить настройки</button>'+
  '</div>'+
  vStatsBlocksCard(s);
}
function vStatsBlocksCard(s){
  var dict=S.statsBlocksDict||{};
  var on=(s.stats_blocks||'').split(',').filter(Boolean);
  var h='<div class="card"><h3>Блоки в разделе «Статистика»</h3>'+
    '<p class="muted" style="margin:8px 0 12px">Уберите галочку — блок перестанет показываться. Ничего не отмечено «пусто» — значит показаны все.</p>';
  Object.keys(dict).forEach(function(k){
    var checked=!on.length||on.indexOf(k)>=0;
    h+='<label style="font-weight:400;display:flex;gap:8px;align-items:center;margin-bottom:8px">'+
      '<input type="checkbox" id="f-sb-'+k+'" style="width:auto;margin:0"'+(checked?' checked':'')+'> '+esc(dict[k])+'</label>';
  });
  h+='<button class="btn-ghost'+(S.busy?' busy':'')+'" data-a="statsBlocksSave" style="margin-top:6px">Сохранить</button></div>';
  return h;
}

/* ---------- Telegram (владелец) ---------- */
function vTg(){
  if(!S.tg){
    api('tg_page').then(function(j){ S.tg=j; render(); }).catch(function(e){ toast(e.message); });
    return '<div class="empty">Загружаем…</div>';
  }
  var t=S.tg;
  var h='<h2 style="margin-bottom:14px">Telegram</h2>'+
  '<div class="card"><h3>Шаг 1 — бот</h3>'+
    '<p class="muted" style="margin:8px 0 12px">Создайте бота у @BotFather и вставьте токен. '+
    (t.tokenSet?'Токен задан'+(t.bot?', бот: @'+esc(t.bot):'')+'.':'Токена пока нет.')+'</p>'+
    '<input type="text" id="f-tgtoken" placeholder="'+(t.tokenSet?'вставьте новый, чтобы заменить':'токен из BotFather')+'">'+
    '<button class="btn-ghost'+(S.busy?' busy':'')+'" data-a="tgToken">Сохранить токен</button>'+
  '</div>'+
  '<div class="card"><h3>Шаг 2 — реле на вашем компьютере</h3>'+
    '<p class="muted" style="margin:8px 0 12px">Хостинг не пускает к Telegram напрямую, сообщения доставляет реле. '+
    'Поставить: <span class="mono">relay/install-relay-launchd.sh</span></p>'+
    '<p>Ключ реле: <span class="mono">'+esc(t.relayKey)+'</span></p>'+
    '<p>Состояние: '+(t.relayLive?'<span class="tag ok">на связи</span>':'<span class="tag bad">молчит</span>')+
    (t.relayAt?' <span class="muted">последний раз '+fmtKratko(t.relayAt)+'</span>':'')+
    (t.queue?' · в очереди: '+t.queue:'')+'</p>'+
  '</div>'+
  '<div class="card"><h3>Шаг 3 — подключить себя</h3>'+
    (t.myLink?'<a class="btn-tg" href="'+esc(t.myLink)+'" target="_blank" rel="noopener">Получать уведомления в Telegram</a>'+
      '<div class="field-hint" style="margin-top:10px">Записи, неявки, «зависшие» ученицы. Менеджеры подключают бота сами — из своего профиля.</div>':
    '<p class="muted">Сначала задайте токен и запустите реле — тогда появится ссылка.</p>')+
  '</div>';
  if(t.chats&&t.chats.length){
    h+='<div class="card"><h3>Кто подключён</h3><div class="scroll"><table style="margin-top:10px"><tr><th>Кто</th><th>Роль</th><th>Telegram</th><th></th></tr>';
    t.chats.forEach(function(c){
      h+='<tr><td>'+esc(c.user_name||c.client_name||c.fname||'—')+'</td>'+
        '<td>'+(c.role==='admin'?'владелец':c.role==='manager'?'менеджер':'ученица')+'</td>'+
        '<td class="mono">'+(c.uname?'@'+esc(c.uname):esc(c.chat))+'</td>'+
        '<td><button class="btn-link" data-a="tgUnbind" data-id="'+c.chat+'">отвязать</button></td></tr>';
    });
    h+='</table></div></div>';
  }
  return h;
}

/* ---------- Профиль ---------- */
function vProfile(){
  var me=S.me;
  var h='<h2 style="margin-bottom:14px">Профиль</h2>'+
  '<div class="card">'+
    '<label>Имя и фамилия</label><input type="text" id="f-pname" value="'+esc(me.name)+'">'+
    '<div class="row"><div><label>Телефон</label><input type="tel" id="f-pphone" value="'+esc(me.phone||'')+'"></div>'+
    '<div><label>Telegram (без @)</label><input type="text" id="f-ptg" value="'+esc(me.tg||'')+'"></div></div>'+
    (me.role!=='admin'
      ?'<label>Постоянная ссылка на созвон (Zoom/Meet — ваша комната)</label>'+
       '<input type="text" id="f-plink" value="'+esc(me.link||'')+'" placeholder="https://…">'+
       '<div class="field-hint">Она уходит ученице при записи, если у слота нет своей одноразовой ссылки.</div>'
      :'')+
    '<label>Новый пароль (пусто — не менять)</label><input type="password" id="f-ppass" autocomplete="new-password">'+
    '<button class="btn'+(S.busy?' busy':'')+'" data-a="profileSave">Сохранить</button>'+
  '</div>';
  if(me.role!=='admin'&&me.recKey){
    var recUrl=((S.boot&&S.boot.site)||location.origin+location.pathname)+'?rec='+encodeURIComponent(me.recKey);
    h+='<div class="card"><h3>Ваша ссылка для записи</h3>'+
      '<p class="muted" style="margin:8px 0 12px">Отправьте её ученице, которую ведёте сами, — по ней она увидит и '+
      'выберет время только у вас, без остальных менеджеров в списке.</p>'+
      '<input type="text" readonly value="'+esc(recUrl)+'" onclick="this.select()">'+
      '<button class="btn-ghost btn-sm" style="margin-top:10px" data-a="copyRec">Скопировать ссылку</button></div>';
  }
  if(S.mgr&&S.mgr.botLink&&!S.mgr.tgOn){
    h+='<div class="card"><h3>Telegram</h3>'+
      '<p class="muted" style="margin:8px 0 12px">Подключите бота — записи и напоминания будут приходить туда.</p>'+
      '<a class="btn-tg" href="'+esc(S.mgr.botLink)+'" target="_blank" rel="noopener">Подключить Telegram</a></div>';
  }else if(S.mgr&&S.mgr.tgOn){
    h+='<div class="card"><h3>Telegram</h3><p><span class="tag ok">подключён</span> — записи и напоминания приходят в бота.</p></div>';
  }
  return h;
}

/* ══════════ Обработчики ══════════ */
function bind(){
  app.querySelectorAll('[data-a]').forEach(function(el){
    el.onclick=function(){ handle(el.dataset.a,el.dataset); };
  });
  var ap=document.getElementById('aPass');
  if(ap) ap.onkeydown=function(e){ if(e.key==='Enter') handle('doLogin',{}); };
  var cs=document.getElementById('f-callsSearch');
  if(cs) cs.oninput=function(){ S.callsSearch=cs.value; renderKeep(cs); };
  var ks=document.getElementById('f-cliSearch');
  if(ks) ks.oninput=function(){ S.cliSearch=ks.value; renderKeep(ks); };
  var ps=document.getElementById('f-pupilSearch');
  if(ps) ps.oninput=function(){ S.pupilSearch=ps.value; renderKeep(ps); };
  var dd=document.getElementById('f-draftDate');
  if(dd) dd.onchange=function(){ S.draftDate=dd.value; S.draftTimes=[]; render(); };
}
function renderKeep(input){
  var id=input.id, pos=input.selectionStart;
  render();
  var el=document.getElementById(id);
  if(el){ el.focus(); try{ el.setSelectionRange(pos,pos); }catch(e){} }
}

function handle(a,d){
  /* — общее — */
  if(a==='reload'){ location.reload(); return; }
  if(a==='toAuth'){ S.view='auth'; render(); return; }
  if(a==='toMy'){
    if(S.me&&S.me.role==='client'||S.k){ S.view='my'; loadMy().then(render); render(); }
    else if(S.me){ S.view='cab'; render(); }
    else { S.view='auth'; render(); }
    return;
  }
  if(a==='logout'){
    api('logout').catch(function(){});
    setTok(''); S.me=null; S.my=null; S.mgr=null; S.clients=null; S.card=null;
    S.view='auth'; S.authTab='login'; render(); return;
  }
  if(a==='authTab'){ S.authTab=d.v; S.pendingMsg=''; render(); return; }

  /* — вход — */
  if(a==='doLogin'){
    act(api('login',{email:val('aEmail'),pass:val('aPass')},true).then(function(j){ if(j) signIn(j,true); }));
    return;
  }
  if(a==='doReg'){
    act(api('register',{email:val('rEmail'),pass:val('rPass'),name:val('rName'),role:val('rRole'),
        phone:val('rPhone'),tg:val('rTg')},true)
      .then(function(j){
        if(!j) return;
        if(j.status==='active'){ signIn(j,true); return; }
        S.pendingMsg=j.msg||'Заявка отправлена.'; S.view='pending';
      }));
    return;
  }
  if(a==='doForgot'){
    act(api('reset_ask',{email:val('aEmail')},true).then(function(j){
      if(j){ toast(j.msg||'Письмо в пути.'); S.authTab='login'; }
    }));
    return;
  }
  if(a==='resetDo'){
    act(api('reset_do',{reset:S.resetToken,pass:val('f-pass')},true).then(function(j){
      if(!j) return;
      history.replaceState(null,'',location.pathname);
      if(j.token){ signIn(j,true); }
      else { S.view='auth'; S.authTab='login'; S.pendingMsg=j.msg||'Пароль сменили.'; }
    }));
    return;
  }

  /* — ученица — */
  if(a==='myTab'){ S.myTab=d.v; render(); return; }
  if(a==='recClear'){
    S.rec=''; try{ localStorage.removeItem('sop_rec'); }catch(e){}
    act(loadMy()); return;
  }
  if(a==='myHowToggle'){ S.myHow=!S.myHow; render(); return; }
  if(a==='photoPick'){
    var fi=document.getElementById('f-photo');
    if(!fi) return;
    fi.onchange=function(){
      if(!fi.files||!fi.files[0]) return;
      act(apiFile('client_photo',fi.files[0]).then(function(j){
        toast('Фото обновлено');
        return loadMy();
      }));
    };
    fi.click();
    return;
  }
  if(a==='photoDel'){
    act(api('client_photo_del',S.k&&!tok()?{k:S.k}:{}).then(function(j){ if(j) return loadMy(); }));
    return;
  }
  if(a==='myProfileSave'){
    var body={name:val('f-myname'),phone:val('f-myphone'),tg:val('f-mytg'),pass:val('f-mypass')};
    if(S.k&&!tok()) body.k=S.k;
    act(api('client_profile_save',body).then(function(j){
      if(!j) return;
      toast('Сохранено');
      return loadMy();
    }));
    return;
  }
  if(a==='pick'){ var pid=parseInt(d.id,10); S.pickedSlot=(S.pickedSlot===pid?null:pid); render(); return; }
  if(a==='doBook'){
    if(!S.pickedSlot) return;
    var body={slot_id:S.pickedSlot};
    if(S.k&&!tok()) body.k=S.k;
    act(api('book',body).then(function(j){
      if(!j) return;
      toast('Вы записаны! Код: '+j.booking.code);
      S.pickedSlot=null;
      return loadMy();
    }));
    return;
  }
  if(a==='openCodeVal'){ act(openCode(d.v)); return; }
  if(a==='moveStart'){
    S.moveMode=true;
    act(openCodeSilent(S.codeView.code).then(function(){ S.moveMode=true; }));
    return;
  }
  if(a==='moveCancel'){ S.moveMode=false; render(); return; }
  if(a==='moveTo'){
    act(api('code_move',{code:S.codeView.code,slot_id:d.id},true).then(function(j){
      if(!j) return;
      toast('Созвон перенесён');
      return openCode(j.booking.code);
    }));
    return;
  }
  if(a==='cancelCode'){
    if(!confirm('Точно отменить запись?')) return;
    act(api('code_cancel',{code:S.codeView.code},true).then(function(j){
      if(!j) return;
      return openCode(S.codeView.code);
    }));
    return;
  }

  /* — кабинет: вкладки — */
  if(a==='tab'){
    S.tab=d.v; S.card=null; S.repFor=null; S.moveFor=null;
    if(d.v==='people') S.users=null;
    if(d.v==='mgrs'){ S.mgrs=null; S.mgrOpen=null; }
    if(d.v==='stats'){ S.stats=null; S.statsPrev=null; }
    if(d.v==='settings') S.settings=null;
    if(d.v==='tg') S.tg=null;
    render(); return;
  }
  if(a==='chip'){ S[d.k]=d.v; S.repFor=null; S.moveFor=null; render(); return; }
  if(a==='callsMine'){ S.callsMine=!S.callsMine; render(); return; }
  if(a==='schedToggle'){ S.schedOpen=!S.schedOpen; render(); return; }

  /* — созвоны — */
  if(a==='mark'){
    act(api('mark',{id:d.id,what:d.v}).then(function(j){
      if(!j) return;
      if(d.v==='held'){
        S.repFor=parseInt(d.id,10); S.rep={note:'',status:'',next:'',next_dt:'',pub:''};
        /* «Все будущие» проведённый созвон уже не показывают — переводим туда,
           где строка видна, иначе форма отчёта откроется в пустоте */
        if(S.callsFilter==='future'){
          var bb=((S.mgr&&S.mgr.bookings)||[]).filter(function(x){ return x.id==d.id; })[0];
          S.callsFilter=(bb&&bb.dt.slice(0,10)===todayISO())?'today':'past';
        }
      }
      return loadMgr().then(function(){ if(S.card) return openCard(S.card.client.id); });
    }));
    return;
  }
  if(a==='markMove'){
    if(!confirm('Попросить ученицу перенести созвон? Запись останется в силе, пока она не выберет новое время.')) return;
    act(api('mark',{id:d.id,what:'move'}).then(function(j){ if(j) toast('Ученице ушла просьба перенести'); }));
    return;
  }
  if(a==='moveOpen'){
    var mid=parseInt(d.id,10);
    S.moveFor=(S.moveFor===mid?null:mid);
    if(S.moveFor && !S.moveTimes){
      Promise.all([api('move_times'),api('staff_managers')]).then(function(rs){
        S.moveTimes=rs[0].times; S.moveMgrs=rs[1].managers; render();
      }).catch(function(e){ toast(e.message); });
    }
    render(); return;
  }
  if(a==='moveClose'){ S.moveFor=null; render(); return; }
  if(a==='staffMoveTo'){
    act(api('staff_reschedule',{id:d.id,slot_id:d.slot}).then(function(j){
      if(j){ S.moveFor=null; S.moveTimes=null; toast('Переставили'); return loadMgr(); }
    }));
    return;
  }
  if(a==='staffMoveCustom'){
    var dtv=val('f-moveDt');
    if(!dtv){ toast('Укажите дату и время'); return; }
    var mgrSel=document.getElementById('f-moveMgr');
    act(api('staff_reschedule',{id:d.id,dt:dtv.replace('T',' '),manager_id:mgrSel?mgrSel.value:''}).then(function(j){
      if(j){ S.moveFor=null; S.moveTimes=null; toast('Переставили'); return loadMgr(); }
    }));
    return;
  }
  if(a==='markCancel'){
    if(!confirm('Отменить созвон? Ученица получит уведомление.')) return;
    act(api('mark',{id:d.id,what:'cancel'}).then(function(j){ if(j) return loadMgr(); }));
    return;
  }
  if(a==='repOpen'){
    var b=((S.mgr&&S.mgr.bookings)||[]).filter(function(x){ return x.id==d.id; })[0];
    S.repFor=parseInt(d.id,10);
    S.rep=b&&b.rep_at?{note:b.rep_note||'',status:b.rep_status||'',next:b.rep_next||'',next_dt:b.rep_next_dt||'',pub:b.rep_pub||''}:{note:'',status:'',next:'',next_dt:'',pub:''};
    render(); return;
  }
  if(a==='repClose'){ S.repFor=null; render(); return; }
  if(a==='repSave'){
    act(api('report_save',{id:d.id,note:val('rep-note'),status:val('rep-status'),next:val('rep-next'),next_dt:val('rep-nextdt'),pub:val('rep-pub')})
      .then(function(j){
        if(!j) return;
        toast('Отчёт сохранён');
        S.repFor=null;
        return loadMgr();
      }));
    return;
  }

  /* — расписание — */
  if(a==='draftTime'){
    var i=S.draftTimes.indexOf(d.v);
    if(i>=0) S.draftTimes.splice(i,1); else S.draftTimes.push(d.v);
    S.draftLink=val('f-draftLink');
    render(); return;
  }
  if(a==='draftHalf'){ S.draftHalf=!S.draftHalf; S.draftLink=val('f-draftLink'); render(); return; }
  if(a==='slotsAdd'){
    if(!S.draftDate||!S.draftTimes.length){ toast('Выберите дату и время'); return; }
    act(api('slots_add',{date:S.draftDate,times:S.draftTimes,link:val('f-draftLink')}).then(function(j){
      if(!j) return;
      toast('Открыто слотов: '+j.added);
      S.draftTimes=[]; S.draftLink='';
      return loadMgr();
    }));
    return;
  }
  if(a==='slotDel'){
    act(api('slot_del',{id:d.id}).then(function(j){ if(j) return loadMgr(); }));
    return;
  }
  if(a==='slotLink'){
    var cur=((S.mgr&&S.mgr.slots)||[]).filter(function(x){ return x.id==d.id; })[0];
    var lnk=prompt('Одноразовая ссылка на этот созвон (пусто — убрать):',cur&&cur.link||'');
    if(lnk===null) return;
    act(api('slot_link',{id:d.id,link:lnk.trim()}).then(function(j){ if(j) return loadMgr(); }));
    return;
  }

  /* — ученицы — */
  if(a==='openCli'){ S.tab='cli'; openCard(d.id); return; }
  if(a==='closeCli'){ S.card=null; S.cardBook=false; loadClients().then(render); render(); return; }
  if(a==='cliDone'){
    if(!confirm('Отметить: онбординг завершён?')) return;
    act(api('client_done',{id:d.id}).then(function(j){ if(j){ toast('Онбординг завершён 🎉'); return openCard(d.id); } }));
    return;
  }
  if(a==='cliWork'){
    act(api('client_work',{id:d.id}).then(function(j){ if(j) return openCard(d.id); }));
    return;
  }
  if(a==='fieldsSave'){
    act(api('client_fields',{id:d.id,start_dt:val('f-startdt'),bonus:val('f-bonus'),
      omni_url:val('f-omni'),deal_url:val('f-deal'),
      lessons_start:val('f-lstart'),lessons_d5:val('f-l5'),lessons_end:val('f-lend')})
      .then(function(j){ if(j){ toast('Сохранено'); return openCard(d.id); } }));
    return;
  }
  if(a==='cliStatus'){
    act(api('client_status',{id:d.id,to:d.v}).then(function(j){ if(j) return openCard(d.id); }));
    return;
  }
  if(a==='cliRefund'){
    var reasons=(S.card&&S.card.refundReasons)||['до взаимодействия','отказ банка','ОПО (период охлаждения)','после работы ОПВ','другое'];
    var msg='Причина возврата — введите номер:\n'+reasons.map(function(r,i){ return (i+1)+' — '+r; }).join('\n');
    var n=prompt(msg);
    if(n===null) return;
    var reason=reasons[parseInt(n,10)-1];
    if(!reason){ toast('Не разобрала номер причины'); return; }
    act(api('client_status',{id:d.id,to:'refund',reason:reason}).then(function(j){ if(j){ toast('Возврат отмечен'); return openCard(d.id); } }));
    return;
  }
  if(a==='cliRefuse'){
    var na=d.v==='1';
    if(na&&!confirm('Отметить: ученица сама отказалась от созвонов? Приглашения ей приходить перестанут, сертификат за ней остаётся.')) return;
    act(api('client_refuse',{id:d.id,to:d.v}).then(function(j){ if(j) return openCard(d.id); }));
    return;
  }
  if(a==='cliInvite'){
    act(api('client_invite',{id:d.id}).then(function(j){ if(j){ toast('Письмо с приглашением ушло'); return openCard(d.id); } }));
    return;
  }
  if(a==='cliPairSave'){
    act(api('client_pair',{id:d.id,pair:val('f-clipair')}).then(function(j){ if(j){ toast('Сохранено'); return openCard(d.id); } }));
    return;
  }
  if(a==='cliMineOnly'){ S.cliMineOnly=!S.cliMineOnly; render(); return; }
  if(a==='cliMissingOnly'){ S.cliMissingOnly=!S.cliMissingOnly; render(); return; }
  if(a==='pairSave'){
    act(api('manager_pair',{id:d.id,pair:val('f-pair-'+d.id)}).then(function(j){ if(j){ toast('Сохранено'); S.users=null; } }));
    return;
  }
  if(a==='copyUrl'){ if(S.card) copyText(S.card.url); return; }
  if(a==='copyRec'){
    var mk=S.me&&S.me.recKey; if(!mk) return;
    copyText(((S.boot&&S.boot.site)||location.origin+location.pathname)+'?rec='+encodeURIComponent(mk));
    return;
  }
  if(a==='cardBook'){ S.cardBook=!S.cardBook; render(); return; }
  if(a==='cardFieldsToggle'){ S.cardFields=!S.cardFields; render(); return; }
  if(a==='bookClient'){
    act(api('book_client',{client_id:d.cli,slot_id:d.id}).then(function(j){
      if(!j) return;
      toast('Записали. Ученице ушло подтверждение.');
      S.cardBook=false;
      return loadMgr().then(function(){ return openCard(d.cli); });
    }));
    return;
  }
  if(a==='noteSave'){
    act(api('client_note',{id:d.id,note:val('f-clinote')}).then(function(j){ if(j) toast('Заметка сохранена'); }));
    return;
  }

  /* — владелец — */
  if(a==='mgrOpen'){ var mid=parseInt(d.id,10); S.mgrOpen=(S.mgrOpen===mid?null:mid); render(); return; }
  if(a==='pupilsToggle'){ S.pupilsOpen=!S.pupilsOpen; render(); return; }
  if(a==='userSet'){
    act(api('user_set',{id:d.id,what:d.v}).then(function(j){ if(j) S.users=null; }));
    return;
  }
  if(a==='userReject'){
    if(!confirm('Закрыть доступ этому аккаунту?')) return;
    act(api('user_set',{id:d.id,what:'reject'}).then(function(j){ if(j) S.users=null; }));
    return;
  }
  if(a==='statsGo'){ S.sFrom=val('f-sFrom'); S.sTo=val('f-sTo'); S.stats=null; S.statsPrev=null; render(); return; }
  if(a==='statsWeek'){
    function iso(d){ var p=function(n){return (n<10?'0':'')+n;}; return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); }
    var now=new Date();
    if(d.v==='all'){ S.sFrom='2026-01-01'; S.sTo=iso(now); }
    else if(d.v==='30'){ var f=new Date(now.getTime()-30*86400000); S.sFrom=iso(f); S.sTo=iso(now); }
    else if(d.v==='month'){ S.sFrom=iso(new Date(now.getFullYear(),now.getMonth(),1)); S.sTo=iso(now); }
    else{
      /* отчётная неделя чт–ср: находим прошедший четверг */
      var back=(now.getDay()-4+7)%7;                 /* 4 = четверг */
      var thu=new Date(now.getTime()-back*86400000);
      if(d.v==='1') thu=new Date(thu.getTime()-7*86400000);
      var wed=new Date(thu.getTime()+6*86400000);
      S.sFrom=iso(thu); S.sTo=iso(wed);
    }
    S.stats=null; S.statsPrev=null; render(); return;
  }
  if(a==='statsCompareToggle'){
    S.statsCompare=checked('f-statscmp');
    if(S.statsCompare&&!S.statsPrev) loadStatsPrev();
    render(); return;
  }
  if(a==='importNow'){
    act(api('import_now').then(function(j){
      if(!j) return;
      var r=j.result;
      toast('Импорт: новых '+r.added+', обновлено '+r.updated+', пропущено '+r.skipped);
      S.settings=null; S.clients=null;
    }));
    return;
  }
  if(a==='settingsSave'){
    act(api('settings_set',{
      org:val('f-org'), duration:val('f-duration'),
      remind24:checked('f-remind24')?'1':'0', remind2:checked('f-remind2')?'1':'0',
      courses_allow:document.getElementById('f-courses')?document.getElementById('f-courses').value:'',
      auto_import:checked('f-autoimport')?'1':'0', import_from:val('f-importfrom'),
      stale_days:val('f-staledays'), alert_email:val('f-alertemail'),
      target_calls:val('f-target'),
      cert_note:document.getElementById('f-certnote')?document.getElementById('f-certnote').value:''
    }).then(function(j){ if(j){ toast('Сохранено'); S.settings=null; } }));
    return;
  }
  if(a==='statsBlocksSave'){
    var dict=S.statsBlocksDict||{};
    var blocks=Object.keys(dict).filter(function(k){ return checked('f-sb-'+k); });
    act(api('stats_blocks_set',{blocks:blocks}).then(function(j){ if(j){ toast('Сохранено'); S.settings=null; } }));
    return;
  }
  if(a==='tgToken'){
    act(api('tg_token',{bot_token:val('f-tgtoken')}).then(function(j){ if(j){ toast('Токен сохранён'); S.tg=null; } }));
    return;
  }
  if(a==='tgUnbind'){
    if(!confirm('Отвязать этот Telegram?')) return;
    act(api('tg_unbind',{chat:d.id}).then(function(j){ if(j) S.tg=null; }));
    return;
  }
  if(a==='profileSave'){
    act(api('profile_save',{name:val('f-pname'),phone:val('f-pphone'),tg:val('f-ptg'),link:val('f-plink'),pass:val('f-ppass')})
      .then(function(j){ if(j){ S.me=j.me; toast('Сохранено'); } }));
    return;
  }
}

/* Обновить данные записи (например, список слотов для переноса), не трогая вид */
function openCodeSilent(code){
  return api('booking',{code:code},true).then(function(j){ S.codeView=j.booking; })
    .catch(function(e){ toast(e.message); });
}
function openCard(id){
  return api('client_card',{id:id}).then(function(j){
    S.card=j; S.cardBook=false; S.tab='cli'; render();
  }).catch(function(e){ toast(e.message); });
}

start();
})();
