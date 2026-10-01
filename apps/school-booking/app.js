(function(){
'use strict';

/* ══════════ КОНСТАНТЫ ══════════ */
var CATEGORIES=[
  'Отношения с партнёром',
  'Близость и сексуальность',
  'После родов, тело и восстановление',
  'Уверенность в себе',
  'Кризис, расставание, развод',
  'Семья и дети',
  'Интимная гимнастика',
  'Другое / пока не сформулировала'
];

var API=(window.PSY_API||'api.php');
var ROLE=(window.PSY_ROLE||'client');
var S={
  view:'home', histFor:null, histLog:null, doneA:null, doneLabel:'', topic:'', pair:null, wantDir:'', pickDir:'', pickedExpert:null, expSearch:'',
  tFrom:'', tTo:'', tStep:'', boot:null, err:'',
  pickedSlot:null, lastBooking:null, clients:null, clientCard:null, expHist:null, bookSearch:'', cliSearch:'', cliKurs:'', eCliSearch:'', eKurs:'', eCliAll:false, cliNew:false, cliFilter:'', cliAll:false, dupesOpen:false, dupePairs:null, cliEdit:null, kursy:null, my:null, period:'d30', pFrom:'', pTo:'', refusals:null, bookFound:null, fExp:'', fStatus:'', fKurs:'', moveFor:null, peopleSearch:'', peopleRole:'', bookErr:'', specFilter:'', fFrom:'', fTo:'', lastWait:null, repEdit:{},
  codeView:null, codeErr:'', moveMode:false, me:null, people:null, profile:null, authTab:'login', pendingMsg:'', resetToken:'',
  exp:null, adm:null, adminTab:'dash',
  draftDate:'', draftTimes:[], halfHours:false, repeat:1,
  busy:false
};
var app=document.getElementById('psy-app');
var poll=null;

/* Впустить человека: положить токен, запомнить, кто он, и открыть
   его кабинет. Роль в токене прежняя, поэтому все разделы работают
   без изменений. */
function signIn(j){
  S.me=j.me;
  var r=j.me.role;
  setTok(r==='client'?'client':(r==='expert'?'expert':'admin'), j.token);
  S.view=homeFor(r);
  render();
  if(r==='expert') loadExpert().then(render);
  if(r==='admin'||r==='owner') loadAdmin().then(render);
  if(r==='client') loadMy().then(render);
}
function homeFor(role){
  if(role==='expert') return 'expert';
  if(role==='admin'||role==='owner') return 'admin';
  return 'my';
}
function loadProfile(){
  return api('client_profile',null,'client').then(function(j){ S.profile=j; }).catch(function(e){ toast(e.message); });
}
function loadMy(){
  return api('my_view','','client').then(function(j){ S.my=j; }).catch(function(){});
}

function startView(){
  /* Старые адреса /spec/ и /koordinator/ пока живы — на время перехода. */
  if(ROLE==='expert') return tok('expert')?'expert':'auth';
  if(ROLE==='admin')  return tok('admin') ?'admin':'auth';
  return S.me ? homeFor(S.me.role) : 'auth';
}
function tok(role){ try{ return localStorage.getItem('shk_tok_'+role)||''; }catch(e){ return ''; } }
function setTok(role,v){ try{ v?localStorage.setItem('shk_tok_'+role,v):localStorage.removeItem('shk_tok_'+role); }catch(e){} }

/* ══════════ API ══════════ */
/* Файл нельзя завернуть в JSON — для загрузки свой путь. */
function apiFile(action,file,role){
  var fd=new FormData(); fd.append('photo',file);
  var t=role?tok(role):'';
  if(t) fd.append('token',t);
  return fetch(API+'?action='+action+(t?'&token='+encodeURIComponent(t):''),
    {method:'POST',headers:t?{Authorization:'Bearer '+t}:{},body:fd})
    .then(function(r){ return r.json(); })
    .then(function(j){ if(!j||!j.ok) throw new Error((j&&j.error)||'Не получилось загрузить'); return j; });
}

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
/* В ленте событий важен порядок, а не день недели — пишем коротко */
function fmtKratko(v){
  var m=['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
  var s=String(v||''); if(s.length<16) return s;
  return parseInt(s.slice(8,10),10)+' '+m[parseInt(s.slice(5,7),10)-1]+', '+s.slice(11,16);
}
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
/* У перенесённых из таблицы записей в поле запроса лежит сырая строка
   «ФИО | контакт | отчёт» — это архив, а не то, с чем ученица пришла. */
function realNote(t, name){
  t=String(t||'').trim();
  if(!t) return '';
  if(t.indexOf(' | ')>=0) return '';                 /* «ФИО | контакт | отчёт» */
  /* В старых таблицах здесь лежали пометки куратора вперемешку с именем:
     «БРОНЬ камилла», «Иванова сп омник». Запросом это не было.
     Разбираем на слова — \b в JavaScript кириллицу не понимает. */
  var junk={'бронь':1,'резерв':1,'омник':1,'вк':1,'сп':1,'ск':1,'сг':1,'макс':1,'max':1,'мах':1,
            'ученица':1,'повторно':1,'июньская':1,'мартовская':1,'майская':1};
  var nameW={};
  String(name||'').toLowerCase().split(/[^0-9a-zа-яё]+/i).forEach(function(w){ if(w) nameW[w]=1; });
  var left=t.toLowerCase().split(/[^0-9a-zа-яё]+/i).filter(function(w){
    return w && !junk[w] && !nameW[w];
  });
  return left.length ? t : '';
}


/* Поиск по спискам. Свести всё к нижнему регистру и выкинуть знаки:
   куратор ищет «иванова», а в базе может лежать «Иванова А.» с телефоном
   через дефисы. */
function hay(){ return Array.prototype.slice.call(arguments).join(' ').toLowerCase().replace(/[^0-9a-zа-яё@._ ]/gi,' '); }
function match(q, text){
  q=(q||'').trim().toLowerCase();
  if(!q) return true;
  return q.split(/\s+/).every(function(w){ return text.indexOf(w)>=0; });
}

function statusTag(b){
  if(b.status==='pending') return '<span class="tag wait">ждёт подтверждения</span>';
  if(b.status==='searching') return '<span class="tag bad">ищем специалиста</span>';
  if(b.status==='held') return '<span class="tag ok">состоялась</span>';
  if(b.status==='noshow') return '<span class="tag bad">не пришла</span>';
  if(b.status==='cancelled') return '<span class="tag">отменена</span>';
  if(isPast(b.dt)) return '<span class="tag wait">ждёт отметки</span>';
  return '<span class="tag">запланирована</span>';
}
function val(id){ var e=document.getElementById(id); return e?String(e.value||'').trim():''; }

/* ══════════ ЗАГРУЗКА ══════════ */
function boot(){
  return api('boot').then(function(j){ S.boot=j; if(j.now) S.skew=dt(j.now).getTime()-Date.now(); }).catch(function(e){ S.err=e.message; });
}
function loadExpert(){
  return api('expert_state',null,'expert').then(function(j){ S.exp=j; })
    .catch(function(e){ if(e.status===401){ setTok('expert',''); S.exp=null; S.view='auth'; } else toast(e.message); });
}
function loadAdmin(){
  return api('admin_state',null,'admin').then(function(j){ S.adm=j; })
    .catch(function(e){ if(e.status===401){ setTok('admin',''); S.adm=null; S.view='auth'; } else toast(e.message); });
}

/* ══════════ РЕНДЕР ══════════ */
function render(){
  var body='';
  if(S.err && !S.boot) body='<div class="card"><h2>Сервер недоступен</h2><p class="muted" style="margin-top:8px">'+esc(S.err)+
    '</p><button class="btn" data-a="reload">Попробовать снова</button></div>';
  else if(S.view==='auth')        body=vAuth();
  else if(S.view==='pending')     body=vPending();
  else if(S.view==='reset')       body=vReset();
  else if(S.view==='home')        body=vHome();
  else if(S.view==='client')      body=vClient();
  else if(S.view==='done')        body=vDone();
  else if(S.view==='waiting')     body=vWaiting();
  else if(S.view==='code')        body=vCode();
  else if(S.view==='my')          body=vMy();
  else if(S.view==='book')        body=vClient();
  else if(S.view==='profile')     body=(S.me&&S.me.role==='client')?vClientProfile():vProfile();
  else if(S.view==='eSlots')      body=S.exp?vExpertSlots():'<div class="card"><div class="empty">Загружаем…</div></div>';
  else if(S.view==='eLinks')      body=S.exp?vExpertLinks():'<div class="card"><div class="empty">Загружаем…</div></div>';
  else if(S.view==='eClients')    body=S.exp?vExpertClients():'<div class="card"><div class="empty">Загружаем…</div></div>';
  else if(S.view==='expertLogin') body=vAuth();
  else if(S.view==='expert')      body=S.exp?(S.expHist?vExpertHistory():vExpert()):'<div class="card"><div class="empty">Загружаем кабинет…</div></div>';
  else if(S.view==='adminLogin')  body=vAuth();
  else if(S.view==='admin')       body=S.adm?vAdmin():'<div class="card"><div class="empty">Загружаем дашборд…</div></div>';

  if(S.me){
    app.innerHTML='<div class="wrap wide">'+topbar()+
      '<div class="shell"><aside class="side">'+sidebar()+'</aside>'+
      '<main class="main">'+body+'</main></div></div>';
  }else{
    var wide=(S.view==='admin')?' wide':'';
    app.innerHTML='<div class="wrap'+wide+'">'+head()+body+'</div>';
  }
  bind();
}
/* Меню: что человек видит, решает его роль. Разделы — отдельные
   страницы, а не блоки на одном длинном свитке. */
function menuFor(role){
  if(role==='client') return [['my','Мои встречи'],['book','Записаться'],['profile','Профиль']];
  if(role==='expert') return [['expert','Мои встречи'],['eSlots','Открыть время'],
                              ['eClients','Мои ученицы'],['eLinks','Ссылки'],['profile','Профиль']];
  var m=[['a:dash','Дашборд'],['a:zayavki','Заявки от учениц'],['a:book','Журнал записей'],['a:wait','Очередь'],
         ['a:cli','Ученицы'],['a:exp','Специалисты'],['a:kursy','Курсы']];
  if(role==='owner') m.push(['a:people','Подтверждение регистрации'],['a:set','Настройки']);
  m.push(['profile','Профиль']);
  return m;
}

function topbar(){
  var name=(S.boot&&S.boot.org)||'Запись к специалисту';
  return '<div class="topbar"><div class="brandmark"><i class="dot"></i><span>'+esc(name)+'</span></div>'+
    '<button class="btn-ghost btn-sm" data-a="signOut">Выйти</button></div>';
}

function sidebar(){
  var me=S.me, cur=(S.view==='admin')?('a:'+S.adminTab):S.view;
  return '<div class="who"><b>'+esc(me.name)+'</b><span>'+esc(roleName(me.role))+'</span></div>'+
    '<div class="nav">'+menuFor(me.role).map(function(m){
      return '<button aria-selected="'+(cur===m[0])+'" data-a="go" data-v="'+m[0]+'">'+esc(m[1])+'</button>';
    }).join('')+'</div>';
}

function head(){
  var name=(S.boot&&S.boot.org)||'Запись к специалисту';
  var atStart=(S.view==='auth'||S.view==='home'||S.view==='client'||S.view==='expertLogin'||S.view==='adminLogin');
  var back=atStart?'':'<button class="btn-ghost btn-sm" data-a="home">← В начало</button>';
  return '<div class="topbar"><div class="brandmark"><i class="dot"></i><span>'+esc(name)+'</span></div>'+back+'</div>';
}

/* ---------- 1. Вход ---------- */
function codeCard(){
  return '<div class="card" style="margin-top:14px"><div class="split">'+
    '<div><h3>Уже записались?</h3><p class="muted" style="margin:6px 0 0">Введите код записи, чтобы посмотреть встречу, перенести её или оставить отзыв.</p></div>'+
    '<div style="flex:0 0 190px"><input type="text" id="codeIn" placeholder="Например, K7QM2" maxlength="6" style="text-transform:uppercase;margin-bottom:8px">'+
    '<button class="btn btn-sm" data-a="openCode" style="width:100%">Открыть запись</button></div>'+
  '</div></div>';
}
/* Кода на каждую встречу мало: сходила пять раз — пять кодов, и ни один
   не показывает остальные. Даём вход по почте: ссылка приходит письмом,
   значит знать чужой адрес недостаточно. */
function myCard(){
  return '<div class="card" style="margin-top:14px"><div class="split">'+
    '<div><h3>Все ваши встречи</h3><p class="muted" style="margin:6px 0 0">Впишите почту, с которой записывались — пришлём ссылку. '+
    'Там видно всю историю, сколько консультаций осталось, и можно перенести или отменить.</p></div>'+
    '<div style="flex:0 0 240px"><input type="email" id="myMail" placeholder="вы@example.com" style="margin-bottom:8px">'+
    '<button class="btn btn-sm" data-a="myAsk" style="width:100%"'+(S.busy?' disabled':'')+'>Прислать ссылку</button></div>'+
  '</div></div>';
}
function vHome(){ return vClient()+myCard()+codeCard(); }

/* Личный кабинет ученицы по ссылке из письма. */
function vMy(){
  var M=S.my;
  if(!M) return '<div class="card"><div class="empty">Загружаем…</div></div>';
  var left = M.allowed>0 ? Math.max(0, M.allowed-M.used) : null;
  var h='<div class="card"><div class="eyebrow">Ваши встречи</div><h1>'+esc(M.name||'Здравствуйте')+'</h1>'+
    '<div class="metrics" style="margin-top:18px">'+
      metric(M.used,'Всего встреч')+
      metric(left===null?'—':left,'Осталось по курсу', left===0)+
      (M.course?metric(esc(M.course),'Курс'):'')+
    '</div>';
  if(M.blocked) h+='<div class="note warn">Записываться сейчас нельзя. Напишите куратору — он подскажет, что делать.</div>';
  else if(left===0) h+='<div class="note">Открытые вам встречи использованы. Не переживайте: тарифы у всех разные, '+
    'и куратор в ближайшее время откроет вам запланированное количество. Нужна встреча срочно — напишите куратору.</div>';
  /* Прочерк ученице ничего не говорит — объясняем, почему и что делать */
  else if(left===null) h+='<div class="note">Сколько консультаций вам положено, мы пока не знаем: '+
    (M.course?'куратор ещё не задал число по вашему курсу.'
            :'в профиле не отмечен курс — от него зависит, сколько встреч доступно.')+
    (M.course?'':' <button class="btn-link" data-a="go" data-v="profile">Отметить курс</button>')+'</div>';
  h+='</div>';

  h+='<div class="card"><h3>История</h3>';
  if(!M.bookings.length) h+='<div class="empty">Встреч пока не было.</div>';
  M.bookings.forEach(function(b){
    var future = b.dt > (S.boot&&S.boot.now||'');
    h+='<div style="border-top:1px solid var(--line);padding-top:14px;margin-top:14px">'+
      '<div class="split"><div><b>'+esc(fmtFull(b.dt))+'</b>'+(b.expert?' — '+esc(b.expert):'')+'</div>'+statusTag(b)+'</div>'+
      (b.link&&future?'<div style="margin-top:8px"><a href="'+esc(b.link)+'" target="_blank" rel="noopener">Ссылка на встречу</a></div>':'')+
      (future&&b.status!=='cancelled'
        ? '<div style="margin-top:10px"><button class="btn-ghost btn-sm" data-a="openCodeVal" data-v="'+esc(b.code)+'">Перенести или отменить</button></div>'
        : '')+
      '</div>';
  });
  return h+'</div>'+(S.me?'':'<button class="btn-ghost" style="margin-top:14px" data-a="home">В начало</button>');
}

/* ---------- 2. Ученица ---------- */
function askTopic(){ return !(S.boot && S.boot.askTopic===false); }
function askPair(){  return !(S.boot && S.boot.askPair ===false); }

function vClient(){
  var times=(S.boot&&S.boot.times)||[];
  var topics=(S.boot&&S.boot.topics)||CATEGORIES;
  var aT=askTopic(), aP=askPair();
  /* Шагов может не быть вовсе: когда специалистов двое и берут они всё,
     выбирать тему не из чего, а лишний экран перед записью только мешает.
     Нумеруем от того, какие шаги включены — экраны рисуются поодиночке,
     поэтому считать «по ходу» нельзя. */
  var nT=aT?1:0, nP=aP?1:0;
  var stepTime=1+nT+nP, stepYou=2+nT+nP;

  /* Шаг 1 — тема. Пока она не выбрана, календарь не показываем. */
  if(aT && !S.topic){
    var h='<div class="card"><div class="eyebrow">Шаг 1 — тема</div><h2>С чем вы хотите поработать?</h2>'+
      '<p class="muted" style="margin-top:8px">Выберите тему — покажем время только тех специалистов, кто с ней работает. '+
      'Подробности расскажете на встрече, здесь достаточно направления мысли.</p></div>';
    h+='<div class="roles" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">';
    topics.forEach(function(t){
      var n=times.filter(function(x){return (x.topics||[]).indexOf(t)>=0;}).length;
      h+='<button class="role" data-a="topic" data-v="'+esc(t)+'"><strong style="font-size:16px">'+esc(t)+'</strong>'+
         '<em>'+(n?(n+' '+plural(n,['слот свободен','слота свободно','слотов свободно'])):'сейчас нет свободных слотов')+'</em></button>';
    });
    return h+'</div>';
  }

  /* Шаг 2 — формат: одна или вдвоём. Парные берут не все специалисты. */
  if(aP && (S.pair===null||S.pair===undefined)){
    var canPair=times.some(function(x){
      return aT ? (x.pairTopics||[]).indexOf(S.topic)>=0 : (x.pairTopics||[]).length>0; });
    var h2='<div class="card"><div class="split"><div><div class="eyebrow">Шаг '+(1+nT)+' — формат</div>'+
      '<h2>Вы придёте одна или вдвоём?</h2></div>'+
      (aT?'<button class="btn-ghost btn-sm" data-a="topicBack">Другая тема</button>':'')+'</div>'+
      '<p class="muted" style="margin-top:10px">Парную консультацию ведут не все специалисты, поэтому от ответа зависит, '+
      'какое время мы покажем.</p></div>';
    h2+='<div class="roles" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">'+
      '<button class="role" data-a="pair" data-v="0"><strong>Одна</strong><em>Индивидуальная консультация</em></button>'+
      (canPair
        ? '<button class="role" data-a="pair" data-v="1"><strong>Вдвоём</strong><em>Парная консультация — придём с партнёром</em></button>'
        : '<div class="role" style="opacity:.55"><strong>Вдвоём</strong><em>'+
          (aT?'По этой теме сейчас нет свободного времени у специалистов, которые ведут пары'
             :'Сейчас нет свободного времени у специалистов, которые ведут парные консультации')+'</em></div>')+
      '</div>';
    return h2;
  }

  var shown=times.filter(function(x){
    if(aP && S.pair==='1') return aT ? (x.pairTopics||[]).indexOf(S.topic)>=0 : (x.pairTopics||[]).length>0;
    return aT ? (x.topics||[]).indexOf(S.topic)>=0 : true;
  });

  /* Выбор специалиста. 62 ученицы из 80 ходят к одной и той же — им важно
     вернуться к своей, а не к «свободному». Выбор необязательный: кто
     впервые, жмёт «любой свободный», и часы не простаивают. */
  var speci=(S.profile&&S.profile.profile&&S.profile.profile.speci)||(S.boot&&S.boot.speci)||[];
  var vybor='';
  if(((S.boot&&S.boot.vyborSpeca)!==false) && speci.length>1){
    var kto=S.specFilter||'';
    vybor='<div class="card"><div class="eyebrow">Шаг '+stepTime+' — к кому</div>'+
      '<h2>Выберите специалиста</h2>'+
      '<p class="muted" style="margin-top:8px">Если уже были на встрече — возвращайтесь к своему специалисту: '+
      'продолжать с человеком, который вас знает, обычно полезнее. Впервые — нажмите «любой свободный», подберём сами.</p>'+
      '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px">'+
      speci.map(function(sp){
        var vybran=String(kto)===String(sp.id);
        return '<button class="chip" aria-pressed="'+vybran+'" data-a="specPick" data-v="'+sp.id+'" '+
          'style="text-align:left;padding:14px 16px;min-width:210px;border-radius:6px">'+
          '<b style="font-size:15px">'+esc(sp.name)+'</b>'+
          /* «ваша» само по себе не читается — говорим, кто это ей */
          (sp.svoy?' <span class="tag ok">ваш специалист</span>'
                 :(sp.byla?' <span class="tag">вы уже были</span>':''))+
          '<div class="muted" style="margin-top:4px;font-size:13px">'+esc(sp.role)+'</div>'+
          '<div style="margin-top:6px;font-size:13px">'+
            (sp.free
              ? 'свободно часов: <b>'+sp.free+'</b>'+(sp.next?'<br>ближайший: '+esc(fmtFull(sp.next)):'')
              : '<span class="muted">свободного времени нет</span>')+
          '</div></button>';
      }).join('')+
      '<button class="chip" aria-pressed="'+(!kto)+'" data-a="specPick" data-v="" '+
        'style="text-align:left;padding:14px 16px;min-width:210px;border-radius:6px">'+
        '<b style="font-size:15px">Любой свободный</b>'+
        '<div class="muted" style="margin-top:4px;font-size:13px">подберём сами</div>'+
        '<div style="margin-top:6px;font-size:13px">все открытые часы</div></button>'+
      '</div></div>';

    /* Выбрали конкретную — показываем только её время */
    if(kto) shown=shown.filter(function(x){ return (x.experts||[]).indexOf(+kto)>=0; });
  }
  var h=vybor+'<div class="card"><div class="split"><div><div class="eyebrow">Шаг '+(vybor?stepTime+1:stepTime)+' — время</div>'+
    '<h2>'+esc(aT ? S.topic : 'Выберите удобное время')+'</h2>'+
    (aP ? '<p class="muted" style="margin-top:4px">'+
          (S.pair==='1'?'Парная консультация':'Индивидуальная консультация')+
          ' · <button class="btn-link" data-a="pairBack">изменить</button></p>' : '')+
    '</div>'+
    (aT ? '<button class="btn-ghost btn-sm" data-a="topicBack">Другая тема</button>' : '')+'</div>'+
    '<p class="muted" style="margin-top:10px">'+
    (S.specFilter
      ? 'Показано время выбранного специалиста. '
      : (aT ? 'Специалиста подберём мы: свободного на это время, работающего с вашей темой и с наименьшей загрузкой. '
            : 'Специалиста подберём мы — свободного на это время. Если вы уже были на консультации, вернём к тому же. '))+
    'Ссылку на встречу увидите сразу после записи. Время '+esc(tzName())+'.</p></div>';

  if(!shown.length){
    var vybrannaya=S.specFilter?speci.filter(function(x){return String(x.id)===String(S.specFilter);})[0]:null;
    if(vybrannaya){
      /* Своя занята — не бросаем: зовём в очередь к ней и показываем остальных */
      /* Имя ставим в именительном: склонять фамилии надёжно нельзя,
         а «у Лебедева Ольга» читается как ошибка. */
      return h+'<div class="card"><div class="note warn"><b>'+esc(vybrannaya.name)+' пока не открыла свободное время.</b> '+
        'Мы напишем вам, как только оно появится — обычно это несколько дней.</div>'+
        '<div style="height:10px"></div>'+
        '<button class="btn-ghost btn-sm" data-a="specPick" data-v="">Посмотреть время других специалистов</button>'+
        '</div>'+vWaitForm();
    }
    return h+'<div class="card"><div class="empty">'+
      (aP && S.pair==='1' ? 'Сейчас нет времени у специалистов, которые ведут парные консультации.'
       : aT ? 'По этой теме сейчас нет открытого времени.'
            : 'Сейчас нет открытого времени.')+
      '</div></div>'+vWaitForm();
  }
  var days={},order=[];
  shown.forEach(function(t){ var d=String(t.dt).slice(0,10); if(!days[d]){days[d]=[];order.push(d);} days[d].push(t); });

  h+='<div class="card">';
  order.forEach(function(d){
    h+='<div class="day"><div class="day-head"><b>'+esc(fmtDay(days[d][0].dt))+'</b>'+
       '<span>'+days[d].length+' '+plural(days[d].length,['слот','слота','слотов'])+'</span></div><div class="tickets">';
    days[d].forEach(function(t){
      h+='<button class="ticket" aria-pressed="'+(S.pickedSlot===t.dt)+'" data-a="pick" data-t="'+esc(t.dt)+'">'+
         fmtTime(t.dt)+'<small>'+S.boot.duration+' мин</small></button>';
    });
    h+='</div></div>';
  });
  h+='</div>';

  if(S.pickedSlot && S.me && S.me.role==='client'){
    /* Своя ученица уже всё про себя рассказала — спрашиваем только запрос. */
    var pr=(S.profile&&S.profile.profile)||null;
    /* Курс нужен, чтобы понять, сколько встреч вам открыто. Раньше об этом
       узнавали отказом после нажатия «Записаться» — теперь говорим сразу
       и даём кнопку, а не оставляем гадать. */
    var nuzhenKurs = ((S.boot&&S.boot.courses)||[]).length && !(pr && pr.courses && pr.courses.length);
    h+='<div class="card"><div class="eyebrow">Шаг '+stepYou+' — подтверждение</div>'+
      '<h2>'+esc(fmtFull(S.pickedSlot))+'</h2>'+
      '<p class="muted" style="margin-top:6px">Записываем как '+esc(S.me.name)+
      (pr&&pr.courses&&pr.courses.length?' · '+esc(pr.courses[0]):'')+'. '+
      '<button class="btn-link" data-a="go" data-v="profile">поправить в профиле</button></p>'+
      (nuzhenKurs
        ? '<div class="note warn" style="margin-top:14px"><b>Сначала отметьте свой курс.</b> '+
          'От него зависит, сколько встреч вам открыто — без него записаться не получится. '+
          'Это одна галочка, время мы пока придержим.<div style="height:10px"></div>'+
          '<button class="btn btn-sm" data-a="go" data-v="profile">Отметить курс в профиле</button></div>'
        : '')+
      '<div style="height:14px"></div>'+
      '<label for="cNote">Опишите запрос парой слов</label>'+
      '<textarea id="cNote" maxlength="300" placeholder="Одна-две фразы — чтобы специалист мог подготовиться к встрече заранее."></textarea>'+
      '<div class="note">Не описывайте здесь детали здоровья и личной жизни. Подробный разговор ждёт вас на самой встрече, наедине с специалистом.</div>'+
      (S.bookErr?'<div class="note warn"><b>Записаться не получилось.</b> '+esc(S.bookErr)+'</div>':'')+
      '<button class="btn" data-a="bookMe"'+(S.busy||nuzhenKurs?' disabled':'')+'>'+
        (S.busy?'Записываем…':'Записаться на '+fmtTime(S.pickedSlot))+'</button></div>';
    return h;
  }

  if(S.pickedSlot){
    var sub=[aT?esc(S.topic):'', aP?(S.pair==='1'?'парная консультация':'индивидуальная'):''].filter(Boolean).join(' · ');
    h+='<div class="card"><div class="eyebrow">Шаг '+stepYou+' — о вас</div>'+
      '<h2>'+esc(fmtFull(S.pickedSlot))+'</h2>'+
      (sub?'<p class="muted" style="margin-top:4px">'+sub+'</p>':'')+
      '<div style="height:16px"></div>'+
      /* Имя и фамилия раздельно: школа сверяет ученицу со своими
         списками, и «как к вам обращаться» для этого не годится. */
      '<div class="row"><div><label for="cFirst">Имя</label>'+
      '<input type="text" id="cFirst" placeholder="Мария" autocomplete="given-name"></div>'+
      '<div><label for="cLast">Фамилия</label>'+
      '<input type="text" id="cLast" placeholder="Иванова" autocomplete="family-name"></div></div>'+
      (((S.boot&&S.boot.courses)||[]).length
        ? '<label for="cCourse">Ваш курс</label><select id="cCourse"><option value="">— выберите —</option>'+
          S.boot.courses.map(function(c){return '<option value="'+esc(c)+'">'+esc(c)+'</option>';}).join('')+'</select>'+
          '<div class="field-hint">От курса зависит, сколько консультаций вам доступно.</div>'
        : '')+
      /* Телеграм и телефон — разные каналы, и путать их в одном поле
         значит потом разбирать вручную, что именно оставили. */
      '<label for="cTg">Telegram</label>'+
      '<input type="text" id="cTg" placeholder="@nickname">'+
      ((S.boot&&S.boot.bot)
        ? '<label style="font-weight:400;display:flex;gap:9px;align-items:flex-start;margin:-4px 0 14px">'+
          '<input type="checkbox" id="cBot" style="width:auto;margin:3px 0 0" checked>'+
          '<span style="font-size:13px">Хочу напоминания в Telegram — после записи покажем кнопку подписки на бота</span></label>'
        : '<div class="field-hint">Напоминания придут на почту.</div>')+
      '<label for="cPhone">Телефон</label>'+
      '<input type="tel" id="cPhone" placeholder="+7 900 000-00-00" autocomplete="tel">'+
      '<div class="field-hint">Достаточно одного из двух, но с телефоном куратору проще найти вас в списках школы.</div>'+
      '<label for="cMail">'+((S.boot&&S.boot.emailReq)?'Почта, с которой вы зарегистрированы на курсе':'Почта — по желанию')+'</label>'+
      '<input type="text" id="cMail" placeholder="вы@example.com" autocomplete="email">'+
      '<label for="cNote">Опишите запрос парой слов</label>'+
      '<textarea id="cNote" maxlength="300" placeholder="Одна-две фразы — чтобы специалист мог подготовиться к встрече заранее."></textarea>'+
      '<div class="note">Не описывайте здесь детали здоровья и личной жизни. Это открытая форма записи — подробный разговор ждёт вас на самой встрече, наедине с специалистом.</div>'+
      '<label style="font-weight:400;display:flex;gap:9px;align-items:flex-start;margin-bottom:16px">'+
      '<input type="checkbox" id="cAgree" style="width:auto;margin:3px 0 0"><span style="font-size:13px">Согласна на обработку моих данных для организации встречи</span></label>'+
      '<button class="btn" data-a="book"'+(S.busy?' disabled':'')+'>'+(S.busy?'Записываем…':'Записаться на '+fmtTime(S.pickedSlot))+'</button></div>';
  }
  return h;
}

/* Нет подходящего времени — не тупик. Оставляет контакт и получает
   сигнал, как только специалист откроет час по её теме. */
function vWaitForm(){
  return '<div class="card"><div class="eyebrow">Свободного времени нет</div>'+
    '<h2>Позвать вас, когда время появится?</h2>'+
    '<p class="muted" style="margin-top:8px">Оставьте контакт — напишем, как только специалист откроет подходящий час. '+
    'Записываться нужно будет самой: время достаётся той, кто успела первой.</p>'+
    '<div style="height:16px"></div>'+
    '<label for="wName">Как к вам обращаться</label>'+
    '<input type="text" id="wName" placeholder="Имя" autocomplete="given-name">'+
    '<label for="wContact">Telegram или телефон</label>'+
    '<input type="text" id="wContact" placeholder="@nickname или +7…">'+
    '<label for="wMail">Почта — по желанию</label>'+
    '<input type="text" id="wMail" placeholder="вы@example.com" autocomplete="email">'+
    '<div class="field-hint">Чем больше каналов, тем выше шанс, что сигнал дойдёт вовремя.</div>'+
    '<button class="btn"'+(S.busy?' disabled':'')+' data-a="wait">'+(S.busy?'Записываем…':'Сообщить, когда появится время')+'</button></div>';
}

function vWaiting(){
  var w=S.lastWait; if(!w){ S.view='home'; return vHome(); }
  var h='<div class="card"><div class="eyebrow">Готово</div><h1>Вы в очереди</h1>'+
    '<p class="muted" style="margin-top:10px">Напишем, как только у специалиста откроется подходящее время. '+
    'Место в очереди не бронирует час — записаться нужно будет самой, и лучше сразу.</p>';
  if(w.tg){
    h+='<div style="height:20px"></div>'+
      '<a class="btn-tg" href="'+esc(w.tg)+'" target="_blank" rel="noopener">Получать сигнал в Telegram</a>'+
      '<div class="field-hint" style="margin-top:10px">Один тап — и бот напишет вам первым, как только время откроется. '+
      'Это самый быстрый канал: письмо и СМС идут дольше.</div>';
  }
  return h+(S.me?'':'<button class="btn-ghost" style="margin-top:16px" data-a="home">В начало</button>')+'</div>';
}

/* ══════════ ЕДИНЫЙ ВХОД ══════════
   Раньше у каждой роли был свой адрес и свой способ попасть внутрь:
   ученица по коду записи, специалист просто выбирал себя из списка без
   пароля, куратор набирал общий пин. Теперь одна дверь на всех. */

/* Профиль ученицы: контакты, курсы и фото по желанию. Специалист видит
   имя и заметки, а связь нужна куратору — чтобы найти человека, когда
   что-то пошло не так. */
function vClientProfile(){
  var P=S.profile;
  if(!P) return '<div class="card"><div class="empty">Загружаем…</div></div>';
  var p=P.profile, all=P.allCourses||[], mine=p.courses||[];
  var left = p.allowed>0 ? Math.max(0,p.allowed-p.used) : null;

  var h='<div class="card"><div class="eyebrow">Профиль</div><h1>'+esc(p.name)+'</h1>'+
    '<div class="metrics" style="margin-top:16px">'+
      metric(p.used,'Встреч прошло')+
      metric(left===null?'—':left,'Осталось по курсу', left===0)+
    '</div>'+
    (left===null?'<div class="field-hint" style="margin-top:10px">Прочерк — значит курс ещё не отмечен. '+
      'Число встреч по вашему тарифу куратор открывает вручную.</div>'
     :left===0?'<div class="field-hint" style="margin-top:10px">Открытые встречи использованы — куратор '+
      'в ближайшее время откроет количество по вашему тарифу.</div>':'')+'</div>';

  h+='<div class="card"><h3>Фотография</h3>'+
    '<p class="muted" style="margin:8px 0 14px">По желанию — специалисту проще узнать вас на встрече. Видите её только вы и школа.</p>'+
    (p.photo
      ? '<img src="'+esc(p.photo)+'" alt="" style="width:120px;height:120px;object-fit:cover;border-radius:60px;display:block;margin-bottom:12px">'
      : '<div style="width:120px;height:120px;border-radius:60px;background:var(--ground);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;color:var(--ink-soft);margin-bottom:12px">нет фото</div>')+
    '<input type="file" id="pFoto" accept="image/jpeg,image/png,image/webp" style="margin-bottom:10px">'+
    /* кнопка и подсказка не в .row: сетка прижимала их друг на друга */
    '<div style="display:flex;gap:12px;align-items:center;margin-bottom:8px">'+
    '<button class="btn-ghost btn-sm" data-a="fotoUp">Загрузить</button>'+
    (p.photo?'<button class="btn-link" data-a="fotoDel">Удалить фото</button>':'')+'</div>'+
    '<div class="field-hint">JPG, PNG или WEBP, до трёх мегабайт.</div></div>';

  h+='<div class="card"><h3>Как с вами связаться</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Почта — главный канал: на неё приходят подтверждение, ссылка на встречу и напоминания. '+
    'Телефон и телеграм нужны куратору, если что-то пойдёт не так.</p>'+
    '<label for="pName">Имя и фамилия</label><input type="text" id="pName" value="'+esc(p.name)+'">'+
    '<label>Почта</label><input type="text" value="'+esc(p.email)+'" disabled>'+
    '<div class="field-hint">Почту менять нельзя — по ней вы входите и по ней школа находит вас в списках. Нужно поменять — напишите куратору.</div>'+
    '<div class="row"><div><label for="pPhone">Телефон</label>'+
      '<input type="tel" id="pPhone" value="'+esc(p.phone||'')+'" placeholder="+7 900 000-00-00"></div>'+
      '<div><label for="pTg">Telegram</label>'+
      '<input type="text" id="pTg" value="'+esc(p.tg?'@'+p.tg:'')+'" placeholder="@nickname"></div></div>';

  if(all.length){
    h+='<label>На каких курсах вы учитесь</label><div style="margin-bottom:14px">'+
      all.map(function(c){
        return '<label style="font-weight:400;display:flex;gap:9px;align-items:center;margin-bottom:8px">'+
          '<input type="checkbox" class="pCourse" value="'+esc(c)+'" style="width:auto"'+
          (mine.indexOf(c)>=0?' checked':'')+'><span style="font-size:14px">'+esc(c)+'</span></label>';
      }).join('')+'</div>'+
      '<div class="field-hint" style="margin-top:-8px">От курса зависит, сколько консультаций вам доступно.</div>';
  }

  h+='<label for="pAbout">Что специалисту стоит знать заранее — по желанию</label>'+
    '<textarea id="pAbout" maxlength="380" placeholder="Одна-две фразы: с чем работаете, что важно учесть.">'+esc(p.about||'')+'</textarea>'+
    '<button class="btn" data-a="profSave">Сохранить</button></div>';

  if(S.boot&&S.boot.bot){
    h+='<div class="card"><h3>Напоминания в Telegram</h3>'+
      (p.tgBound
        ? '<p class="muted" style="margin:8px 0 0"><span class="tag ok">подключён</span> Напоминания и ссылка на встречу приходят вам в Telegram.</p>'
        : '<p class="muted" style="margin:8px 0 12px">Подключите бота — напоминания о встрече придут туда же, куда вы обычно пишете. '+
          'Ссылку на встречу он тоже пришлёт.</p>'+
          /* Личная ссылка: бот сразу понимает, кто пришёл, и код спрашивать не надо */
          '<a class="btn-tg" href="'+esc(p.tgLink||('https://t.me/'+(S.boot.bot||'')))+'" target="_blank" rel="noopener">Подключить за один тап</a>'+
          '<div class="field-hint" style="margin-top:10px">Ссылку нужно открыть отсюда, а не искать бота в поиске: '+
          'так он сразу поймёт, что это вы. Подключается один раз — на все будущие записи.</div>')+
      '</div>';
  }else{
    h+='<div class="card"><h3>Напоминания в Telegram</h3>'+
      '<p class="muted" style="margin:8px 0 0">Бот пока не подключён — напоминания приходят на почту.</p></div>';
  }

  h+='<div class="card"><h3>Пароль</h3>'+
    '<p class="muted" style="margin:8px 0 12px">Пришлём на '+esc(p.email)+' ссылку — по ней зададите новый.</p>'+
    '<button class="btn-ghost" data-a="myForgot">Прислать ссылку на смену пароля</button></div>';
  return h;
}

function vProfile(){
  var m=S.me||{};
  var roles=(S.boot&&S.boot.roles)||{};
  var h='<div class="card"><div class="eyebrow">Профиль</div><h1>'+esc(m.name||'')+'</h1>'+
    '<table style="margin-top:16px"><tr><th style="width:160px">Почта</th><td>'+esc(m.email||'')+'</td></tr>'+
    '<tr><th>Роль</th><td>'+esc(roleName(m.role))+'</td></tr></table>';

  /* Специалисту без почты не приходит ничего: ни новая запись, ни напоминание
     за два часа. Раньше её мог вписать только куратор — теперь и она сама. */
  if(m.role==='expert' && S.exp && S.exp.expert){
    var e=S.exp.expert;
    h+='<div style="height:22px"></div><h3>Куда вам писать</h3>'+
      '<p class="muted" style="margin:8px 0 14px">На эту почту приходят новые записи, напоминание за два часа '+
      'и вопрос, как прошла встреча. Телефон нужен куратору, если что-то срочное.</p>'+
      '<div class="row"><div><label for="myMail">Почта</label>'+
      '<input type="email" id="myMail" value="'+esc(e.email||'')+'" placeholder="вы@example.com"></div>'+
      '<div><label for="myTel">Телефон</label>'+
      '<input type="tel" id="myTel" value="'+esc(e.phone?telVid(e.phone):'')+'" placeholder="+7 999 123-45-67"></div></div>'+
      (e.email?'':'<div class="note warn">Почты пока нет — уведомления о новых записях до вас не доходят.</div>')+
      '<div style="height:10px"></div>'+
      '<button class="btn btn-sm" data-a="expMeSave">Сохранить</button>'+
      (S.exp.tgLink && !e.tgBound
        ? '<div class="note" style="margin-top:14px"><b>Telegram.</b> Если подключить, всё то же самое будет приходить ещё и в него — быстрее почты. '+
          '<a href="'+esc(S.exp.tgLink)+'" target="_blank" rel="noopener">Подключить за один тап</a>.</div>'
        : '');
  }

  /* Куратору и владельцу — личная ссылка на бота. Раньше был один код
     на всех: не понять, кто подключился, и не отключить одного. */
  if((m.role==='admin'||m.role==='owner')){
    h+='<div style="height:22px"></div><h3>Уведомления в Telegram</h3>';
    if(m.tgBound){
      h+='<p class="muted" style="margin:8px 0 0"><span class="tag ok">подключено</span> '+
        'Новые записи, отмены, неявки и отказы приходят вам в Telegram.</p>';
    }else if(m.tgLink){
      h+='<p class="muted" style="margin:8px 0 12px">Подключите — и будете сразу узнавать о новых записях, '+
        'отменах и о том, что ученица не смогла записаться. Это то, что иначе замечаешь через день.</p>'+
        '<a class="btn-tg" href="'+esc(m.tgLink)+'" target="_blank" rel="noopener">Подключить за один тап</a>'+
        '<div class="field-hint" style="margin-top:10px">Ссылку нужно открыть отсюда, а не искать бота в поиске: '+
        'так он поймёт, что это вы. Ссылка личная — никому не пересылайте.</div>';
    }else{
      h+='<p class="muted" style="margin:8px 0 0">Бот ещё не настроен — это делается в Настройках.</p>';
    }
  }

  return h+
    (m.legacy?'<div class="note" style="margin-top:14px">Вы вошли по коду, по-старому. Заведите себе аккаунт с почтой и паролем — так надёжнее, и пароль можно восстановить.</div>':'')+
    '<div style="height:18px"></div>'+
    (m.legacy?'':'<h3>Сменить пароль</h3>'+
    '<p class="muted" style="margin:8px 0 12px">Пришлём на вашу почту ссылку — по ней зададите новый.</p>'+
    '<button class="btn-ghost" data-a="myForgot">Прислать ссылку на смену пароля</button>')+
    '</div>';
}

function vAuth(){
  var tab=S.authTab||'login';
  var h='<div class="card"><div class="eyebrow">Запись к специалисту</div>'+
    '<h1>'+(tab==='login'?'Вход':'Регистрация')+'</h1>'+
    '<div class="chips" style="margin:16px 0">'+
      '<button class="chip" aria-pressed="'+(tab==='login')+'" data-a="authTab" data-v="login">У меня есть аккаунт</button>'+
      '<button class="chip" aria-pressed="'+(tab==='reg')+'" data-a="authTab" data-v="reg">Я здесь впервые</button>'+
    '</div>';

  if(tab==='login'){
    h+='<label for="aEmail">Почта</label><input type="email" id="aEmail" autocomplete="email" placeholder="вы@example.com">'+
      '<label for="aPass">Пароль</label><input type="password" id="aPass" autocomplete="current-password">'+
      '<button class="btn"'+(S.busy?' disabled':'')+' data-a="doLogin">'+(S.busy?'Заходим…':'Войти')+'</button>'+
      '<div style="height:10px"></div>'+
      '<button class="btn-link" data-a="authTab" data-v="forgot">Забыли пароль?</button>';
  }else if(tab==='forgot'){
    h+='<p class="muted" style="margin-bottom:14px">Впишите почту — пришлём ссылку, чтобы задать новый пароль.</p>'+
      '<label for="aEmail">Почта</label><input type="email" id="aEmail" autocomplete="email">'+
      '<button class="btn"'+(S.busy?' disabled':'')+' data-a="doForgot">Прислать ссылку</button>'+
      '<div style="height:10px"></div><button class="btn-link" data-a="authTab" data-v="login">Вернуться ко входу</button>';
  }else{
    var roles=(S.boot&&S.boot.roles)||{client:'ученица',expert:'специалист',admin:'куратор'};
    h+='<label for="rName">Имя и фамилия</label><input type="text" id="rName" placeholder="Мария Иванова" autocomplete="name">'+
      '<label for="rEmail">Почта</label><input type="email" id="rEmail" autocomplete="email" placeholder="вы@example.com">'+
      '<div class="field-hint">Для учениц — та, на которую оформлен курс: по ней школа находит вас в своих списках.</div>'+
      '<div class="row"><div><label for="rPhone">Телефон</label>'+
      '<input type="tel" id="rPhone" autocomplete="tel" placeholder="+7 999 123-45-67"></div>'+
      '<div><label for="rTg">Telegram</label>'+
      '<input type="text" id="rTg" placeholder="@nickname"></div></div>'+
      '<div class="field-hint">Ученицам: по телефону или телеграму школа тоже вас найдёт, даже если курс оформлен '+
      'на другую почту. Психологам и кураторам телефон нужен, чтобы связаться, когда что-то срочное.</div>'+
      '<label for="rPass">Пароль</label><input type="password" id="rPass" autocomplete="new-password" placeholder="минимум шесть символов">'+
      '<label for="rRole">Кто вы</label><select id="rRole">'+
        Object.keys(roles).map(function(k){ return '<option value="'+k+'">'+esc(roles[k])+'</option>'; }).join('')+
      '</select>'+
      '<div class="field-hint">Ученицам доступ открывается сразу. Психологам и кураторам — после подтверждения владельцем: это доступ к чужим личным данным.</div>'+
      '<button class="btn"'+(S.busy?' disabled':'')+' data-a="doReg">'+(S.busy?'Отправляем…':'Зарегистрироваться')+'</button>';
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
    '<label for="nPass">Новый пароль</label><input type="password" id="nPass" autocomplete="new-password" placeholder="минимум шесть символов">'+
    '<button class="btn"'+(S.busy?' disabled':'')+' data-a="doReset">Сохранить и войти</button></div>';
}

function tzName(){
  var tz=(S.boot&&S.boot.tz)||'Europe/Moscow';
  return tz==='Europe/Moscow'?'московское':('в часовом поясе '+tz);
}

function vDone(){
  var b=S.lastBooking; if(!b){ S.view='home'; return vHome(); }
  if(b.pending){
    return '<div class="card"><div class="eyebrow">Готово</div><h1>Заявка принята</h1>'+
      '<p class="muted" style="margin-top:12px">Это время уже держится за вами — другая ученица на него не запишется. '+
      'Куратор подтвердит запись в течение суток, и тогда придёт ссылка на встречу.</p>'+
      '<div style="height:18px"></div>'+
      '<table><tr><th style="width:150px">Когда</th><td><b>'+esc(fmtFull(b.dt))+'</b></td></tr>'+
      '<tr><th>Код заявки</th><td><span class="code">'+esc(b.code)+'</span></td></tr></table>'+
      '<div class="note" style="margin-top:14px">Сохраните код — по нему можно посмотреть заявку или отменить её. '+
      'Если код потеряется, куратор найдёт вас по имени.</div>'+
      '<button class="btn-ghost" data-a="home">В начало</button></div>';
  }
  var h='<div class="card"><div class="eyebrow">Готово</div><h1>Вы записаны</h1><div style="height:18px"></div>'+
    '<table><tr><th style="width:150px">Когда</th><td><b>'+esc(fmtFull(b.dt))+'</b></td></tr>'+
    '<tr><th>Специалист</th><td>'+esc(b.expert)+'</td></tr>'+
    '<tr><th>Ссылка</th><td>'+(b.link?'<a href="'+esc(b.link)+'" target="_blank" rel="noopener">'+esc(b.link)+'</a>':'<span class="tag wait">координатор пришлёт ссылку</span>')+'</td></tr>'+
    '<tr><th>Код записи</th><td><span class="code">'+esc(b.code)+'</span></td></tr></table>';
  if(b.tg){
    h+='<div style="height:20px"></div>'+
      '<a class="btn-tg" href="'+esc(b.tg)+'" target="_blank" rel="noopener">Включить напоминания в Telegram</a>'+
      '<div class="field-hint" style="margin-top:10px">Один тап — и бот пришлёт подтверждение со ссылкой, напомнит за сутки и за два часа, '+
      'а после встречи спросит, как всё прошло. Без этого напоминания придётся слать вручную.</div>';
  }
  h+='<div class="note" style="margin-top:14px">Сохраните код — по нему можно открыть запись, отменить её или оставить отзыв.</div>'+
    (S.me&&S.me.role==='client'
      ? '<button class="btn-ghost" data-a="go" data-v="my">К моим встречам</button>'
      : '<button class="btn-ghost" data-a="home">В начало</button>')+'</div>';
  return h;
}

/* ---------- 3. Запись по коду: просмотр, отмена, отзыв ---------- */
function vCode(){
  var b=S.codeView;
  if(!b) return '<div class="card"><h2>Запись не найдена</h2><p class="muted" style="margin-top:8px">'+
    esc(S.codeErr||'Проверьте код — пять символов из подтверждения.')+'</p><button class="btn-ghost" data-a="home">Назад</button></div>';

  var over=dt(b.dt).getTime()+(b.duration||50)*60000 < nowMs();
  var h='<div class="card"><div class="eyebrow">Ваша запись</div><h2>'+esc(fmtFull(b.dt))+'</h2><div style="height:14px"></div>'+
    '<table><tr><th style="width:140px">Специалист</th><td>'+esc(b.expert)+'</td></tr>'+
    '<tr><th>Ссылка</th><td>'+(b.link?'<a href="'+esc(b.link)+'" target="_blank" rel="noopener">Открыть встречу</a>':'уточняется')+'</td></tr>'+
    '<tr><th>Статус</th><td>'+statusTag(b)+'</td></tr></table>';
  if(b.tg && !over) h+='<div style="height:16px"></div><a class="btn-tg" href="'+esc(b.tg)+'" target="_blank" rel="noopener">Напоминания в Telegram</a>';
  if(!over && b.status!=='cancelled')
    h+='<div style="height:16px"></div>'+
       '<button class="btn-ghost btn-sm" data-a="moveStart">Перенести на другое время</button> '+
       '<button class="btn-link" data-a="cancelCode" style="margin-left:10px">Отменить запись</button>';
  h+='</div>';

  if(S.moveMode && !over && b.status!=='cancelled'){
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
  return h+'<div class="card"><div class="eyebrow">Отзыв</div><h2>Как прошла встреча?</h2><div style="height:16px"></div>'+
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
/* История ученицы глазами специалиста. Ученица может ходить к разным
   психологам, и приходить на встречу вслепую — значит тратить сессию
   на пересказ того, что уже записано. */
function vExpertHistory(){
  var H=S.expHist; if(!H) return '';
  var h='<div class="card"><div class="split"><div><div class="eyebrow">История ученицы</div>'+
    '<h3>'+esc(H.name)+'</h3></div>'+
    '<button class="btn-ghost btn-sm" data-a="histClose">Закрыть</button></div>';
  if(H.client){
    h+='<div class="muted" style="margin-top:8px">'+
      (H.client.course?'Курс: '+esc(H.client.course)+' · ':'')+
      'встреч: '+H.client.used+(H.client.allowed?' из '+H.client.allowed:'')+'</div>';
    if(H.client.note) h+='<div class="note" style="margin-top:12px">Куратор: '+esc(H.client.note)+'</div>';
  }
  if(!H.history.length) h+='<div class="empty" style="margin-top:14px">Это первая встреча.</div>';
  H.history.forEach(function(b){
    h+='<div style="border-top:1px solid var(--line);padding-top:12px;margin-top:12px">'+
      '<div class="split"><div><b>'+esc(fmtFull(b.dt))+'</b> — '+esc(b.expert||'—')+'</div>'+statusTag(b)+'</div>'+
      (realNote(b.note,H.name)?'<div class="muted" style="margin-top:6px">Запрос при записи: '+esc(realNote(b.note,H.name))+'</div>':'')+
      (b.adm_note?'<div class="muted" style="margin-top:4px">Куратор: '+esc(b.adm_note)+'</div>':'')+
      (b.rep_req?'<div class="muted" style="margin-top:4px">С чем пришла: '+esc(b.rep_req)+'</div>':'')+
      (b.rep_plan?'<div class="muted" style="margin-top:4px">Что предложили: '+esc(b.rep_plan)+'</div>':'')+
      '</div>';
  });
  return h+'</div>';
}

/* Кабинет специалиста разнесён по страницам: раньше это был один
   длинный свиток, где календарь, ссылки и история жили вперемешку. */
/* Мои ученицы — глазами специалиста. Ищем по фамилии или курсу, видим,
   сколько встреч уже было и сколько положено, и открываем историю. */
function vExpertClients(){
  var D=S.exp;
  if(!D) return '<div class="card"><div class="empty">Загружаем…</div></div>';
  var vse=D.students||[], kurs=S.eKurs||'';
  var h='<div class="card"><div class="eyebrow">Мои ученицы</div>'+
    '<h3>'+vse.length+' '+plural(vse.length,['ученица','ученицы','учениц'])+'</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Все, кто хоть раз к вам записывался. '+
    'Курс проставляет куратор — если у кого-то пусто, спросите у него.</p>';
  if(!vse.length) return h+'<div class="empty">Пока никого. Откройте время — и ученицы появятся.</div></div>';

  h+=filtrUchenic({items:vse, search:S.eCliSearch, kurs:kurs, act:'eKurs',
                   inputId:'eCliSearch', hint:'Поиск по фамилии или имени'});

  var L=vse.filter(function(c){
    return match(S.eCliSearch, hay(c.name, c.fio, c.course, c.courses, c.contact)) && poKursu(c, kurs);
  });
  if(!L.length) return h+'<div class="empty">Никого не нашли.</div></div>';
  if(S.eCliSearch||kurs) h+='<div class="field-hint" style="margin-bottom:10px">Нашлось: <b>'+L.length+'</b> из '+vse.length+'.</div>';
  var vsegoL=L.length, obrezL=false;
  if(!S.eCliAll && vsegoL>60){ L=L.slice(0,60); obrezL=true; }

  h+='<div class="scroll"><table><tr><th>Ученица</th><th>Курс</th>'+
     '<th class="num">Со мной</th><th class="num">Всего из положенных</th>'+
     '<th>Последняя</th><th>Ближайшая</th><th></th></tr>';
  L.forEach(function(c){
    var kon=c.allowed>0 && c.used>=c.allowed;
    h+='<tr><td><b>'+esc(c.fio||c.name)+'</b></td>'+
       '<td>'+(c.course?esc(c.course):(c.courses?'<span class="muted">'+esc(c.courses)+'</span>':'<span class="muted">не указан</span>'))+'</td>'+
       '<td class="num">'+c.so_mnoy+'</td>'+
       '<td class="num" style="white-space:nowrap">'+c.used+' из '+
         '<input type="number" min="0" max="99" style="width:62px;margin:0;display:inline-block" '+
           'data-f="eaAllowed" data-ck="'+esc(c.ckey)+'" value="'+(c.allowed||0)+'"> '+
         '<button class="btn-link" data-a="eaSave" data-ck="'+esc(c.ckey)+'">сохранить</button>'+
         (kon?' <span class="tag bad">кончились</span>':'')+'</td>'+
       '<td class="muted">'+(c.last?esc(fmtFull(c.last)):'—')+'</td>'+
       '<td>'+(c.next?'<b>'+esc(fmtFull(c.next))+'</b>':'<span class="muted">не записана</span>')+'</td>'+
       '<td><button class="btn-link" data-a="cliHist" data-id="'+c.id+'">история</button></td></tr>';
  });
  h+='</table></div>';
  if(obrezL) h+='<div style="margin-top:12px"><button class="btn-ghost btn-sm" data-a="eCliAll">'+
    'Показать всех ('+vsegoL+')</button></div>';
  return h+'</div>';
}

function vExpertLinks(){
  var D=S.exp, e=D.expert;
  var free=(D.linksFree||0);
  var h='';
  h+='<div class="card"><div class="eyebrow">Ссылки на встречи</div><h3>Запас одноразовых ссылок'+
    ' <span class="tag'+(free===0?' bad':(free<5?' wait':' ok'))+'">'+free+'</span></h3>'+
    '<p class="muted" style="margin:8px 0 14px">Каждой ученице уходит своя ссылка — по чужой на её встречу не зайдут. '+
    'Нарежьте их пачкой в SaluteJazz или Телемосте и вставьте сюда, по одной в строке. '+
    (free===0?'<b>Запас пуст: сейчас ссылка не выдаётся.</b>':(free<5?'<b>Осталось мало — пополните.</b>':'Система берёт по одной на запись, отменённые возвращает.'))+'</p>'+
    '<textarea id="eLinks" rows="4" placeholder="https://salutejazz.ru/calls/…&#10;https://salutejazz.ru/calls/…"></textarea>'+
    '<button class="btn-ghost btn-sm" data-a="linksAdd">Добавить в запас</button></div>';

  return h;
}

function vExpertSlots(){
  var D=S.exp, e=D.expert;
  var slots=D.slots||[];
  var freeSlots=slots.filter(function(s){return !s.booking_id && !isPast(s.dt);});
  var h='';
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
  var from=S.tFrom||'10:00', to=S.tTo||'18:00', step=S.tStep||'60';

  h+='<div class="card"><div class="eyebrow">Ваш календарь</div><h3>Откройте время</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Отметьте часы, в которые вам удобно, — и нажмите «Открыть». '+
    'Ученицы увидят только время, без вашего имени.'+
    (D.shared?' Кабинет для встреч один на всех, поэтому время достаётся тому, кто открыл его первым.':'')+'</p>'+
    '<label for="dDate">День</label><input type="date" id="dDate" value="'+esc(day)+'" min="'+todayISO()+'">'+

    /* Главный способ — отметить нужные часы руками. Промежуток со «шагом»
       давал плывущее время (10:00, 10:50, 11:40…) и выглядел хаосом. */
    '<label style="margin-top:14px">Выберите часы</label>'+
    '<label style="font-weight:400;display:flex;gap:9px;align-items:center;margin:0 0 10px">'+
      '<input type="checkbox" id="dHalf" style="width:auto;margin:0"'+(S.halfHours?' checked':'')+'>'+
      '<span style="font-size:14px">Показать и половины часа (10:30, 11:30…)</span></label>'+
    '<div class="chips">'+
      (function(){
        var o='', shag=S.halfHours?30:60;
        for(var m=8*60;m<=21*60;m+=shag){
          var v=(Math.floor(m/60)<10?'0':'')+Math.floor(m/60)+':'+(m%60<10?'0':'')+(m%60);
          var est=taken[v], vybran=S.draftTimes.indexOf(v)>=0;
          o+='<button class="chip'+(est?' taken':'')+'" '+(est?'disabled title="уже открыто"':'aria-pressed="'+vybran+'" data-a="time" data-v="'+v+'"')+'>'+v+'</button>';
        }
        return o;
      })()+
    '</div>'+
    '<label for="dRep" style="margin-top:14px">Повторить на несколько дней подряд</label>'+
    '<select id="dRep"><option value="1">только этот день</option><option value="3">3 дня подряд</option>'+
    '<option value="5">5 дней подряд</option><option value="7">неделю</option><option value="14">две недели</option></select>'+
    '<button class="btn"'+(S.draftTimes.length?'':' disabled')+' data-a="addSlots">'+
      (S.draftTimes.length?'Открыть '+S.draftTimes.length+' '+plural(S.draftTimes.length,['час','часа','часов']):'Отметьте часы выше')+
    '</button>'+
    '<div class="field-hint" style="margin-top:10px">Серые часы уже открыты. Убрать открытый час можно ниже, в списке этого дня.</div>'+

    /* Промежутком удобно ставить целый день — оставляем, но вторым способом */
    '<div style="height:26px"></div>'+
    '<div class="eyebrow" style="margin-bottom:8px">Или сразу промежутком</div>'+
    '<div class="chips" style="margin-bottom:12px">'+
      [['10:00','14:00','Первая половина'],
       ['14:00','18:00','Вторая половина'],
       ['10:00','18:00','10–18'],
       ['09:00','21:00','9–21']].map(function(p){
        return '<button class="chip" aria-pressed="'+(from===p[0]&&to===p[1])+'" data-a="tPreset" '+
               'data-from="'+p[0]+'" data-to="'+p[1]+'">'+p[2]+'</button>';
      }).join('')+
    '</div>'+
    '<div class="row">'+
      '<div><label for="tFrom">Начало</label><select id="tFrom">'+timeOpts(from)+'</select></div>'+
      '<div><label for="tTo">Конец</label><select id="tTo">'+timeOpts(to)+'</select></div>'+
      '<div><label for="tStep">Каждые</label><select id="tStep">'+
        ['30','60','90','120'].map(function(v){return '<option value="'+v+'"'+(v===step?' selected':'')+'>'+v+' мин</option>';}).join('')+
      '</select></div>'+
    '</div>'+
    '<button class="btn-ghost" data-a="addRange">Открыть промежутком</button>'+
    '<div class="field-hint" style="margin-top:10px">«Каждые 60 минут» даст ровные часы: 10:00, 11:00, 12:00. '+
    'Встреча идёт '+(D.duration||50)+' минут, остальное — перерыв.</div>';

  var dayOpen=slots.filter(function(s){return String(s.dt).slice(0,10)===day;});
  h+='<div style="height:22px"></div><label>Что уже открыто у вас в этот день</label>';
  if(!dayOpen.length) h+='<div class="muted">Пока ничего. Отметьте часы выше.</div>';
  else{
    h+='<div class="field-hint" style="margin:0 0 8px">Нажмите на час, чтобы убрать его. '+
       'Занятые убрать нельзя — сначала отмените встречу.</div>'+
       '<div class="chips">';
    dayOpen.forEach(function(s){
      h+='<button class="chip'+(s.booking_id?' taken':'')+'" '+(s.booking_id?'disabled title="уже занято ученицей"':'data-a="delSlot" data-id="'+s.id+'" title="убрать"')+'>'+
         fmtTime(s.dt)+(s.booking_id?' · занято':' ×')+'</button>';
    });
    h+='</div>';
  }
  if(D.shared){
    var others=(D.busy||[]).filter(function(b){return String(b.dt).slice(0,10)===day;});
    h+='<div style="height:18px"></div><label>Занято в общем кабинете</label>';
    if(!others.length) h+='<div class="muted">В этот день кабинет свободен весь день.</div>';
    else{
      h+='<div class="chips">';
      others.forEach(function(b){
        h+='<span class="chip taken" title="'+esc(b.who)+'">'+fmtTime(b.dt)+' · занято</span>';
      });
      h+='</div>';
    }
  }
  h+='</div>';

  h+='<div class="card"><h3>Мои открытые слоты</h3><div style="height:12px"></div>';
  if(!freeSlots.length) h+='<div class="empty">Открытых слотов нет. Ученицы вас не увидят.</div>';
  else{
    h+='<div class="tickets">';
    freeSlots.forEach(function(s){
      h+='<button class="ticket" data-a="delSlot" data-id="'+s.id+'" title="Убрать слот">'+fmtTime(s.dt)+
         '<small>'+dt(s.dt).toLocaleDateString('ru-RU',{day:'numeric',month:'short'})+' · убрать</small></button>';
    });
    h+='</div>';
  }
  h+='</div>';

  return h;
}

/* Одна встреча в кабинете специалиста. Рисуется одинаково и в «нужно
   отметить», и в истории — разница только в том, отмечен ли исход. */
function vstrechaBlok(b, D, staff){
  var h='';
    var cnt=(D.counts||{})[b.ckey];
    h+='<div style="border-top:1px solid var(--line);padding-top:16px;margin-top:16px">'+
       '<div class="split"><div><b>'+esc(fmtFull(b.dt))+'</b> — '+esc(b.client_name)+
       (cnt&&cnt.allowed?' <span class="tag'+(cnt.used>=cnt.allowed?' wait':'')+'">'+cnt.used+' из '+cnt.allowed+'</span>':'')+
       ' <button class="btn-link" data-a="cliHist" data-id="'+b.id+'">история</button>'+
       ' <span class="tag">'+esc(b.category)+'</span></div>'+statusTag(b)+'</div>';
    /* Форма нужна там, где исход ещё не отмечен. Где отмечен — короткая
       строка: это уже история, а не задача. */
    var done = staff ? (b.status==='held'||b.status==='noshow') : !!b.rep_at;
    if(done && !S.repEdit[b.id]){
      h+='<div class="muted" style="margin-top:8px">'+
        (staff
          ? ((b.rep_req||b.rep_plan)
              ? [b.rep_req?'Запрос: '+esc(b.rep_req):'', b.rep_plan?'предложили: '+esc(b.rep_plan):''].filter(Boolean).join(', ')
              : 'Заметок нет')
          : 'Отчёт сдан: '+esc(b.rep_plan||'—')+
            (b.paid==1?' · <span class="tag ok">оплата '+esc(b.paid_sum)+' ₽</span>':''))+
        ' · <button class="btn-link" data-a="repEdit" data-id="'+b.id+'">изменить</button></div>';
    }else{
      h+='<div style="margin-top:14px">'+
        '<label>Встреча состоялась?</label><select data-f="status" data-id="'+b.id+'">'+
        '<option value="held"'+(b.status==='held'?' selected':'')+'>Да, провели</option>'+
        '<option value="noshow"'+(b.status==='noshow'?' selected':'')+'>Ученица не пришла</option>'+
        '<option value="cancelled">Отменили заранее</option></select>'+
        (staff
          ? '<label>Запрос — по желанию</label><input type="text" data-f="req" data-id="'+b.id+'" value="'+esc(b.rep_req||'')+'" placeholder="С чем пришла">'+
            '<label>Что предложили — по желанию</label><input type="text" data-f="plan" data-id="'+b.id+'" value="'+esc(b.rep_plan||'')+'" placeholder="Что порекомендовали, о чём договорились">'
          : '<label>Запрос ученицы</label><input type="text" data-f="req" data-id="'+b.id+'">'+
            '<label>Гипотеза и план</label><input type="text" data-f="plan" data-id="'+b.id+'">'+
            '<label>Что предложили дальше</label><input type="text" data-f="next" data-id="'+b.id+'">'+
            '<label>Оплатили пакет?</label><div class="row"><select data-f="paid" data-id="'+b.id+'"><option value="no">Нет</option><option value="yes">Да</option></select>'+
            '<input type="number" data-f="sum" data-id="'+b.id+'" placeholder="Сумма, ₽"></div>')+
        '<button class="btn btn-sm" data-a="saveReport" data-id="'+b.id+'">'+(staff?'Сохранить':'Сдать отчёт')+'</button></div>';
    }
    h+='</div>';
  return h;
}

function vExpert(){
  var D=S.exp, e=D.expert, quota=D.quota;
  var slots=D.slots||[], bk=D.bookings||[];
  var freeSlots=slots.filter(function(s){return !s.booking_id && !isPast(s.dt);});
  var upcoming=bk.filter(function(b){return !isPast(b.dt) && b.status!=='cancelled';});
  var past=bk.filter(function(b){return isPast(b.dt) && b.status!=='cancelled';});
  var noRep=past.filter(function(b){return !b.rep_at;});
  var staff=!!D.staff;
  /* Штатному специалисту интересна не квота и не долг по отчётам,
     а что происходило за последний месяц. */
  var monthAgo=nowMs()-30*24*3600*1000;
  var recent=past.filter(function(b){return dt(b.dt).getTime()>=monthAgo;});
  var rHeld=recent.filter(function(b){return b.status==='held';}).length;
  var rNo=recent.filter(function(b){return b.status==='noshow';}).length;

  /* Сводка — первое, что видит психолог: что сегодня и что завтра. */
  var h=blokDni(D.svodka, true);
  /* «Выйти» живёт в шапке — второй кнопки здесь не нужно. */
  h+='<div class="card"><div class="eyebrow">Кабинет</div><h1>'+esc(e.name)+'</h1>'+
    (S.me?'':'<div style="margin-top:10px"><button class="btn-ghost btn-sm" data-a="eLogout">Выйти</button></div>')+
    '<div class="metrics" style="margin-top:18px">'+
    (staff
      ? metric(upcoming.length,'Впереди встреч')+
        metric(freeSlots.length,'Открытых часов')+
        metric(rHeld,'Провели за 30 дней')+
        metric((rHeld+rNo)?Math.round(rHeld/(rHeld+rNo)*100)+'%':'—','Дошли за 30 дней',(rHeld+rNo)>0&&rHeld/(rHeld+rNo)<0.75)
      : metric(bk.filter(function(b){return b.status!=='cancelled';}).length+' / '+quota,'Учениц из квоты')+
        metric(past.filter(function(b){return b.status==='held';}).length,'Встреч прошло')+
        metric(noRep.length,'Отчётов не сдано',noRep.length>0))+
    '</div>';
  if(!e.tgBound && D.tgLink) h+='<div class="note warn"><b>Telegram не подключён.</b> Без него вы не получите уведомление о новой записи и напоминание за два часа. '+
    '<a href="'+esc(D.tgLink)+'" target="_blank" rel="noopener">Подключить за один тап</a>.</div>';
  h+='</div>';

  /* Темы и парные консультации. Если у ученицы этих шагов нет, то и
     отмечать здесь нечего — блок только сбивал бы с толку. */
  var mine=(e.topics||[]), all=(D.allTopics||[]);
  if(askTopic() || askPair()){
  h+='<div class="card"><div class="eyebrow">Профиль</div><h3>'+(askTopic()?'С какими темами вы работаете':'Формат консультаций')+'</h3>'+
    (askTopic()?'<p class="muted" style="margin:8px 0 14px">Ученица выбирает тему первой и видит время только тех, кто с ней работает. '+
    'Чем честнее отметите, тем меньше придёт «не ваших» запросов.</p>':'<div style="height:12px"></div>');
  if(askTopic()){
    all.forEach(function(t){
      h+='<label style="font-weight:400;display:flex;gap:9px;align-items:flex-start;margin-bottom:10px">'+
         '<input type="checkbox" class="topicBox" value="'+esc(t)+'" style="width:auto;margin:3px 0 0"'+
         (mine.indexOf(t)>=0?' checked':'')+'><span style="font-size:14px">'+esc(t)+'</span></label>';
    });
    if(!e.topicsSet) h+='<div class="note">Пока ничего не отмечено — система считает, что вы берётесь за любую тему.</div>';
  }
  if(askPair()){
    h+='<div style="height:14px"></div><label style="font-weight:400;display:flex;gap:9px;align-items:flex-start;padding-top:12px;border-top:1px solid var(--line)">'+
       '<input type="checkbox" id="ePairs" style="width:auto;margin:3px 0 0"'+(e.pairs?' checked':'')+'>'+
       '<span style="font-size:14px"><b>Беру парные консультации</b><br>'+
       '<span class="muted">Ученица отвечает, придёт она одна или с партнёром. Если галочки нет, парные запросы к вам не попадут.</span></span></label>';
  }
  h+='<div style="height:12px"></div>'+
     '<button class="btn-ghost btn-sm" data-a="saveTopics">Сохранить</button></div>';
  }

  h+='<div class="card"><h3>Ближайшие встречи</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Напишите ученице накануне — с кем поговорили заранее, те доходят чаще. '+
    'Не отвечает — отметьте, куратор подключится.</p>';
  if(!upcoming.length) h+='<div class="empty">Записей пока нет.</div>';
  else{
    upcoming.forEach(function(b){
      var tel=(b.phone||'').replace(/\D/g,'');
      h+='<div style="border-top:1px solid var(--line);padding-top:14px;margin-top:14px">'+
        '<div class="split"><div><b>'+esc(fmtFull(b.dt))+'</b> — '+esc(b.client_name)+
          (b.pair==1?' <span class="tag bad">пара</span>':'')+
          '<div class="muted" style="margin-top:4px">'+esc(b.category)+
          (realNote(b.note,b.client_name)?' · «'+esc(realNote(b.note,b.client_name))+'»':'')+'</div></div>'+
        '<div>'+(b.confirm==='yes'?'<span class="tag ok">ученица подтвердила</span>'
                :b.confirm==='no'?'<span class="tag bad">не отвечает</span>'
                :'<span class="tag wait">не подтверждён</span>')+'</div></div>'+
        '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">'+
        (tel?'<a class="btn-ghost btn-sm" style="text-decoration:none" href="tel:+'+esc(tel)+'">Позвонить '+esc(b.phone)+'</a>':'')+
        (b.tg_nick?'<a class="btn-ghost btn-sm" style="text-decoration:none" href="https://t.me/'+esc(b.tg_nick)+'" target="_blank" rel="noopener">Написать @'+esc(b.tg_nick)+'</a>':'')+
        '<button class="btn-ghost btn-sm" data-a="cliHist" data-id="'+b.id+'">История ученицы</button>'+
        '<button class="btn-link" data-a="expCancel" data-id="'+b.id+'">Отменить встречу</button>'+
        (!tel&&!b.tg_nick&&b.contact&&b.contact!=='—'
          ? '<span class="muted">Контакт: '+esc(b.contact)+'</span>'
          : (!tel&&!b.tg_nick?'<span class="muted">Связи нет — спросите у куратора</span>':''))+
        (b.link?'<a class="btn-ghost btn-sm" style="text-decoration:none" href="'+esc(b.link)+'" target="_blank" rel="noopener">Ссылка на встречу</a>':'')+
        '</div>'+
        /* Без ссылки встреча не состоится, поэтому просим её здесь же,
           а не отправляем специалиста искать, где это делается. */
        (b.link?'':'<div class="note warn" style="margin-top:10px">'+
          '<b>Ссылки на эту встречу пока нет.</b> Создайте комнату и вставьте ссылку — '+
          'ученице она уйдёт сразу.'+
          '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">'+
          '<input type="text" id="el'+b.id+'" style="margin:0;flex:1;min-width:200px" placeholder="https://…">'+
          '<button class="btn btn-sm" data-a="eSetLink" data-id="'+b.id+'">Сохранить</button></div></div>')+
        '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">'+
        (b.confirm!=='yes'?'<button class="btn btn-sm" data-a="confYes" data-id="'+b.id+'">Ученица подтвердила</button>':'')+
        (b.confirm!=='no'?'<button class="btn-ghost btn-sm" data-a="confNo" data-id="'+b.id+'">Не отвечает</button>':'')+
        (b.confirm?'<button class="btn-link" data-a="confClear" data-id="'+b.id+'" style="margin-left:6px">снять отметку</button>':'')+
        '</div></div>';
    });
  }
  h+='</div>';

  /* Работа и история — разные вещи. Раньше формы отметки были вперемешку
     с готовыми встречами, и было не видно, где кончается дело. */
  var nado = staff ? past.filter(function(b){ return b.status!=='held' && b.status!=='noshow'; }) : [];
  var gotovo = staff ? past.filter(function(b){ return b.status==='held' || b.status==='noshow'; }) : past;
  nado.sort(function(x,y){ return dt(y.dt).getTime()-dt(x.dt).getTime(); });
  gotovo.sort(function(x,y){ return dt(y.dt).getTime()-dt(x.dt).getTime(); });

  if(staff){
    h+='<div class="card"><h3>Нужно отметить: '+nado.length+'</h3>'+
      '<p class="muted" style="margin:8px 0 14px">Состоялась встреча или нет. '+
      'Заметки — по желанию, их видите вы и координатор.</p>';
    if(!nado.length) h+='<div class="empty">Всё отмечено. Спасибо.</div>';
    nado.forEach(function(b){ h+=vstrechaBlok(b, D, staff); });
    h+='</div>';
  }

  h+='<div class="card"><h3>'+(staff?'История встреч':'Прошедшие встречи')+'</h3><p class="muted" style="margin:8px 0 14px">'+
    (staff
      ? 'Уже отмеченные. Если ошиблись — можно изменить.'
      : 'Отчёт сдаётся сразу после встречи. Пока отчёта нет, новых учениц система отдаёт другим.')+'</p>';
  if(!gotovo.length) h+='<div class="empty">Пока пусто.</div>';
  /* Показываем разумный кусок — у специалиста с двухлетней историей
     иначе выходит стена из сотен строк. */
  var shownPast=gotovo.slice();
  if(staff && shownPast.length>40) shownPast=shownPast.slice(0,40);
  shownPast.forEach(function(b){ h+=vstrechaBlok(b, D, staff); });
  if(staff && gotovo.length>shownPast.length)
    h+='<div class="muted" style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">Показаны 40 последних из '+gotovo.length+
       '. Полная история — у координатора, выгрузкой в таблицу.</div>';
  return h+'</div>';
}

/* ---------- 5. Координатор ---------- */
/* Люди и доступы. Ученице аккаунт открывается сам, а специалиста и
   куратора пускает владелец: это доступ к чужим личным данным. */
function loadPeople(){
  return api('users_list',null,'admin')
    .then(function(j){ S.people=j; })
    .catch(function(e){ toast(e.message); });
}

function roleName(k){
  var r=(S.boot&&S.boot.roles)||{};
  return r[k] || {owner:'владелец', admin:'куратор', expert:'специалист', client:'ученица'}[k] || k;
}

function aPeople(){
  var P=S.people;
  if(!P) return '<div class="card"><div class="empty">Загружаем…</div></div>';
  var pend=P.users.filter(function(u){return u.status==='pending';});
  var rest=P.users.filter(function(u){return u.status!=='pending';});

  var h='<div class="card"><h3>Ждут подтверждения'+(pend.length?' · '+pend.length:'')+'</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Специалист и куратор видят чужие личные данные, поэтому доступ открываете вы. '+
    'Ученицам подтверждение не нужно — они заходят сразу.</p>';
  if(!pend.length) h+='<div class="empty">Новых заявок нет.</div>';
  pend.forEach(function(u){
    h+='<div style="border-top:1px solid var(--line);padding-top:14px;margin-top:14px">'+
      '<div class="split"><div><b>'+esc(u.name)+'</b> <span class="tag">'+esc(roleName(u.role))+'</span>'+
      '<div class="muted mono" style="margin-top:4px">'+esc(u.email)+'</div></div>'+
      '<span class="muted">'+esc(fmtDay(u.created_at))+'</span></div>';
    if(u.role==='expert'){
      h+='<label style="margin-top:12px">Чья это карточка</label>'+
        '<select data-f="uexp" data-id="'+u.id+'"><option value="">завести новую</option>'+
        P.experts.map(function(e){ return '<option value="'+e.id+'">'+esc(e.name)+'</option>'; }).join('')+
        '</select>'+
        '<div class="field-hint">Если специалист уже есть в системе — свяжите с ним, иначе его встречи останутся в другой карточке.</div>';
    }
    h+='<div class="row" style="margin-top:8px">'+
      '<button class="btn btn-sm" data-a="uOk" data-id="'+u.id+'" data-role="'+esc(u.role)+'">Открыть доступ</button>'+
      '<button class="btn-ghost btn-sm" data-a="uNo" data-id="'+u.id+'">Отклонить</button></div></div>';
  });
  h+='</div>';

  h+='<div class="card"><h3>Все, у кого есть аккаунт</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Здесь меняются роли и закрывается доступ. '+
    'Закрыли — человек сразу перестаёт входить, а всё, что он вёл, остаётся на месте.</p>';

  /* Поиск и срез по ролям: аккаунтов будет сотни, глазами не полистаешь */
  var vseRoli={client:'ученица',expert:'специалист',admin:'куратор',owner:'владелец'};
  h+='<input type="text" id="peopleSearch" placeholder="Поиск по фамилии или почте" value="'+esc(S.peopleSearch||'')+'" autocomplete="off">'+
    '<div class="chips" style="margin:10px 0 4px">'+
    [['','Все'],['client','Ученицы'],['expert','Специалисты'],['admin','Кураторы'],['owner','Владельцы']].map(function(f){
      var kolvo=f[0]?rest.filter(function(u){return u.role===f[0];}).length:rest.length;
      return '<button class="chip" aria-pressed="'+((S.peopleRole||'')===f[0])+'" data-a="peopleRole" data-v="'+f[0]+'">'+
             f[1]+' <b>'+kolvo+'</b></button>';
    }).join('')+'</div>';

  var LU=rest.filter(function(u){
    if(S.peopleRole && u.role!==S.peopleRole) return false;
    return match(S.peopleSearch, hay(u.name, u.email, u.phone, u.tg));
  });
  if((S.peopleSearch||S.peopleRole) )
    h+='<div class="field-hint" style="margin-bottom:10px">Нашлось: <b>'+LU.length+'</b> из '+rest.length+'.</div>';
  if(!LU.length) return h+'<div class="empty">Никого не нашли.</div></div>';

  h+='<div class="scroll"><table>'+
    '<tr><th>Имя</th><th>Связь</th><th>Роль</th><th>Статус</th><th>Заходил</th><th></th></tr>';
  var ya=(S.me&&S.me.id)||0;
  LU.forEach(function(u){
    var otkryt=u.status==='active';
    var sam=String(u.id)===String(ya);
    h+='<tr><td><b>'+esc(u.name)+'</b></td>'+
      '<td class="mono">'+esc(u.email)+
        (u.phone?'<div class="muted">'+esc(telVid(u.phone))+'</div>':'')+
        (u.tg?'<div class="muted">@'+esc(u.tg)+'</div>':'')+'</td>'+
      '<td>'+(sam
        ? esc(roleName(u.role))+' <span class="muted">(вы)</span>'
        : '<select data-a="userRole" data-id="'+u.id+'" style="margin:0;min-width:130px">'+
          Object.keys(vseRoli).map(function(k){
            return '<option value="'+k+'"'+(u.role===k?' selected':'')+'>'+vseRoli[k]+'</option>';
          }).join('')+'</select>')+'</td>'+
      '<td>'+(otkryt?'<span class="tag ok">открыт</span>':'<span class="tag bad">закрыт</span>')+'</td>'+
      '<td class="muted">'+(u.last_at?esc(fmtDay(u.last_at)):'ни разу')+'</td>'+
      '<td style="white-space:nowrap">'+
        (sam ? '<span class="muted">—</span>'
          : '<button class="btn-link" data-a="userAccess" data-id="'+u.id+'" data-v="'+(otkryt?'0':'1')+'"'+
            (otkryt?' style="color:var(--alarm)"':'')+'>'+(otkryt?'закрыть доступ':'вернуть доступ')+'</button>')+
      '</td></tr>';
  });
  return h+'</table></div></div>';
}

function vAdmin(){
  /* Вкладки переехали в общее меню наверху — второй ряд здесь был бы
     двумя навигациями подряд. */
  var owner=(S.me&&S.me.role==='owner')||(S.adm&&S.adm.role==='owner');
  if(S.adminTab==='msg') S.adminTab='dash';
  if(S.adminTab==='tg')  S.adminTab='set';
  if(!owner && (S.adminTab==='set'||S.adminTab==='people')) S.adminTab='dash';
  var h='';
  if(S.adminTab==='dash') h+=aDash();
  if(S.adminTab==='book') h+=aBookings();
  if(S.adminTab==='zayavki') h+=aPending();
  if(S.adminTab==='wait') h+=aWaiting();
  if(S.adminTab==='cli')  h+=(S.clientCard?aClientCard():(S.dupesOpen?aDupes():aClients()));
  if(S.adminTab==='exp')  h+=aExperts();
  if(S.adminTab==='kursy') h+=aCourses();
  if(S.adminTab==='tg')   h+=aTelegram();
  if(S.adminTab==='set')  h+=aSettings()+aTelegram();
  if(S.adminTab==='people') h+=aPeople();
  return h;
}

/* Очередь: те, кому не хватило времени. Система зовёт их сама,
   когда специалист открывает часы; здесь видно, кого позвали, а кто ещё ждёт. */
/* Ученицы и их запас консультаций. Сколько положено — от курса; сверху
   координатор добавляет руками: вернулась из возврата, докупила, договорились. */
/* Телефон в базе лежит только цифрами — читать его так невозможно,
   поэтому к показу собираем обратно в привычный вид. */
function telVid(t){
  var d=String(t||'').replace(/\D/g,'');
  if(d.length===11 && (d[0]==='7'||d[0]==='8'))
    return '+7 '+d.slice(1,4)+' '+d.slice(4,7)+'-'+d.slice(7,9)+'-'+d.slice(9);
  if(d.length===10) return '+7 '+d.slice(0,3)+' '+d.slice(3,6)+'-'+d.slice(6,8)+'-'+d.slice(8);
  return String(t||'');
}

/* ---------- Сводка «сегодня и завтра» ----------
   Один блок для психолога и куратора: что уже прошло, что ещё сегодня,
   и к чему готовиться завтра. Отвечает на утренний вопрос «что у меня». */
function blokDni(S2, svoy){
  if(!S2) return '';
  var stroka=function(b){
    var proshla = b.dt <= (S.boot&&S.boot.now||'');
    var metka = b.status==='held'  ? '<span class="tag ok">провели</span>'
              : b.status==='noshow'? '<span class="tag bad">не пришла</span>'
              : proshla            ? '<span class="tag wait">не отмечена</span>'
              : b.confirm==='yes'  ? '<span class="tag ok">подтвердила</span>'
              : b.confirm==='no'   ? '<span class="tag bad">не отвечает</span>'
                                   : '';
    return '<tr><td class="num" style="width:58px;padding-right:12px"><b>'+esc(fmtTime(b.dt))+'</b></td>'+
      '<td>'+esc(b.name)+'</td>'+
      /* Курс отдельной колонкой: под именем мелким его не замечали.
         Пусто — говорим прямо, это сигнал куратору проставить. */
      '<td>'+(b.course?esc(b.course):'<span class="muted">курс не указан</span>')+'</td>'+
      (svoy?'':'<td class="muted">'+esc(b.ename||'—')+'</td>')+
      '<td class="mono muted" style="font-size:12px">'+esc(b.svyaz||'—')+'</td>'+
      '<td>'+metka+'</td>'+
      /* Ссылка кликабельная прямо здесь. Раньше ячейка была пустой, когда
         ссылка есть, — специалист шёл за ней в бота и копировал вручную. */
      '<td style="white-space:nowrap">'+(b.link
        ? '<a class="btn-ghost btn-sm" style="text-decoration:none" href="'+esc(b.link)+'" target="_blank" rel="noopener">Открыть встречу</a>'
        : '<span class="tag bad">нет ссылки</span>')+'</td>'+
      /* И отметка исхода тут же — за ней тоже ходили в бота. */
      '<td style="white-space:nowrap">'+
        ((svoy && proshla && b.status!=='held' && b.status!=='noshow')
          ? '<button class="btn btn-sm" data-a="denOtmetka" data-id="'+b.id+'" data-v="held">Провели</button> '+
            '<button class="btn-ghost btn-sm" data-a="denOtmetka" data-id="'+b.id+'" data-v="noshow">Не пришла</button>'
          : '')+
      '</td></tr>';
  };
  var tabl=function(spisok, pusto){
    if(!spisok.length) return '<div class="empty">'+pusto+'</div>';
    /* Шапка: без неё непонятно, что за столбцы. Класс «uzkaya» снимает
       общий min-width на 900px — эта таблица помещается и без прокрутки. */
    return '<div class="scroll"><table class="uzkaya">'+
      '<tr><th>Время</th><th>Ученица</th><th>Курс</th>'+(svoy?'':'<th>Специалист</th>')+
      '<th>Связь</th><th>Отметка</th><th>Ссылка</th><th></th></tr>'+
      spisok.map(stroka).join('')+'</table></div>';
  };

  var h='<div class="card"><div class="split"><div><div class="eyebrow">'+
    (svoy?'Ваш день':'Сегодня в школе')+'</div><h3>'+fmtDay(S.boot&&S.boot.now||'')+'</h3></div></div>'+
    '<div class="metrics" style="margin-top:16px">'+
      metric(S2.proshlo,'Провели сегодня')+
      metric(S2.vperedi,'Ещё сегодня')+
      metric(S2.zavtra.length,'Завтра')+
      (S2.bezOtmetki?metric(S2.bezOtmetki,'Ждут отметки',true):'')+
      (S2.zayavki?metric(S2.zayavki,'Заявки без ответа',true):'')+
    '</div>';

  h+='<div style="height:18px"></div><div class="eyebrow" style="margin-bottom:8px">Сегодня</div>'+
     tabl(S2.segodnya, svoy?'Сегодня встреч нет — свободный день.':'Сегодня встреч нет.');

  h+='<div style="height:20px"></div><div class="eyebrow" style="margin-bottom:8px">Завтра</div>'+
     tabl(S2.zavtra, 'На завтра пока никто не записан.');
  return h+'</div>';
}

/* ---------- Фильтр по ученицам ----------
   Один и тот же блок в кабинете куратора и специалиста: строка поиска и
   курсы со счётчиком, сколько на каждом. Курс у ученицы может быть
   проставлен куратором (course) или отмечен ею самой в профиле (courses) —
   для поиска берём то, что есть, иначе половина списка выпадет из фильтра. */
function kursOf(x){
  var k=String((x&&x.course)||'').trim();
  if(!k) k=String((x&&x.courses)||'').split(',')[0].trim();
  return k;
}
function poKursu(x, kurs){
  if(!kurs) return true;
  var k=kursOf(x);
  return kurs==='—' ? !k : k===kurs;
}
/* o: {items, search, kurs, act (data-a чипсов), inputId, hint} */
function filtrUchenic(o){
  var skolko={}, bez=0;
  o.items.forEach(function(x){
    var k=kursOf(x);
    if(k) skolko[k]=(skolko[k]||0)+1; else bez++;
  });
  var kursy=Object.keys(skolko).sort(function(a,b){ return skolko[b]-skolko[a] || a.localeCompare(b,'ru'); });
  var h='<input type="text" id="'+o.inputId+'" placeholder="'+esc(o.hint||'Поиск по фамилии, имени или связи')+
        '" value="'+esc(o.search||'')+'" autocomplete="off">';
  h+='<div class="chips" style="margin:10px 0 2px">'+
    '<button class="chip" aria-pressed="'+(!o.kurs)+'" data-a="'+o.act+'" data-v="">Все ('+o.items.length+')</button>'+
    kursy.map(function(k){
      return '<button class="chip" aria-pressed="'+(o.kurs===k)+'" data-a="'+o.act+'" data-v="'+esc(k)+'">'+
             esc(k)+' <b>'+skolko[k]+'</b></button>';
    }).join('')+
    (bez?'<button class="chip" aria-pressed="'+(o.kurs==='—')+'" data-a="'+o.act+'" data-v="—">Курс не указан <b>'+bez+'</b></button>':'')+
    '</div>';
  return h;
}

/* Проверка дублей: пары карточек, признаки совпадения и два решения —
   «один человек» (склеить) или «разные» (запомнить и больше не спрашивать). */
function aDupes(){
  var P=S.dupePairs;
  var h='<div class="card"><div class="split"><div><div class="eyebrow">Ученицы</div>'+
    '<h3>Проверка дублей</h3></div>'+
    '<button class="btn-ghost btn-sm" data-a="dupesClose">← К списку учениц</button></div>'+
    '<p class="muted" style="margin:10px 0 0">Для каждой пары написано, почему мы думаем, что это один человек. '+
    'При объединении история встреч, запас и связь съезжаются в одну карточку — ничего не теряется.</p></div>';
  if(!P) return h+'<div class="card"><div class="empty">Ищем совпадения…</div></div>';
  if(!P.length) return h+'<div class="card"><div class="empty">Дублей не видно. Все карточки выглядят разными людьми.</div></div>';

  h+='<div class="field-hint" style="margin:0 0 12px">Осталось проверить: <b>'+P.length+'</b> '+
     plural(P.length,['пара','пары','пар'])+'.</div>';
  P.forEach(function(p,i){
    var kart=function(c,glav){
      var svyaz=[c.phone?telVid(c.phone):'', c.tg?'@'+c.tg:'', c.email].filter(Boolean);
      if(!svyaz.length && c.contact && c.contact!=='—') svyaz=[c.contact];
      return '<div style="flex:1;min-width:230px;border:1px solid var(--line'+(glav?'-deep':'')+');border-radius:4px;padding:12px">'+
        '<b>'+esc(c.name)+'</b>'+(glav?' <span class="tag ok">останется</span>':' <span class="tag">вольётся</span>')+
        '<div class="mono muted" style="margin-top:6px">'+(svyaz.length?svyaz.map(esc).join('<br>'):'связи нет')+'</div>'+
        '<div class="muted" style="margin-top:6px">'+
          (c.course?esc(c.course)+' · ':'')+'встреч: '+c.used+
          (c.tgBot?' · бот подключён':'')+' · заведена '+esc(c.at)+'</div></div>';
    };
    var a=p.main===0?p.a:p.b, b2=p.main===0?p.b:p.a;
    h+='<div class="card">'+
      '<div class="eyebrow" style="margin-bottom:10px">Пара '+(i+1)+' · совпадает: '+esc(p.why.join(', '))+'</div>'+
      '<div style="display:flex;gap:12px;flex-wrap:wrap">'+kart(a,true)+kart(b2,false)+'</div>'+
      '<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">'+
      '<button class="btn btn-sm" data-a="dupeYes" data-main="'+a.id+'" data-dup="'+b2.id+'">Один человек — объединить</button>'+
      '<button class="btn-ghost btn-sm" data-a="dupeNo" data-a-id="'+p.a.id+'" data-b-id="'+p.b.id+'">Разные люди</button>'+
      '</div></div>';
  });
  return h;
}

function aClients(){
  var C=S.clients;
  if(!C) return '<div class="card"><div class="empty">Загружаем…</div></div>';
  var vse=C.clients, kursy=C.courses||{};
  var dupes=vse.filter(function(c){return (c.dupe||[]).length;});
  var srez=S.cliFilter||'', kurs=S.cliKurs||'';

  var h='<div class="card"><div class="split"><div>'+
    '<div class="eyebrow">Ученицы</div><h3>Кто учится и сколько консультаций осталось</h3></div>'+
    '<div style="display:flex;gap:8px;flex-wrap:wrap">'+
    '<button class="btn btn-sm" data-a="cliNew">'+(S.cliNew?'Свернуть':'Добавить ученицу')+'</button>'+
    '<button class="btn-ghost btn-sm" data-a="csvCli">Скачать список</button>'+
    '</div></div>';

  /* Форма заведения. Ученица попадает в систему сама при первой записи,
     но курс и запас консультаций часто известны раньше — тогда её заводят
     руками, и первая же запись подклеится к этой карточке. */
  if(S.cliNew){
    h+='<div style="border-top:1px solid var(--line);margin-top:16px;padding-top:16px">'+
      '<div class="row"><div><label for="nuName">Фамилия и имя</label>'+
        '<input type="text" id="nuName" placeholder="Иванова Мария" autocomplete="off"></div>'+
      '<div><label for="nuCourse">Курс</label><select id="nuCourse">'+
        '<option value="">— не указан —</option>'+
        Object.keys(kursy).map(function(k){
          return '<option value="'+esc(k)+'">'+esc(k)+(kursy[k]?' ('+kursy[k]+' консультаций)':'')+'</option>';
        }).join('')+'</select></div></div>'+
      '<div class="row"><div><label for="nuPhone">Телефон</label>'+
        '<input type="tel" id="nuPhone" placeholder="+7 999 123-45-67" autocomplete="off"></div>'+
      '<div><label for="nuTg">Телеграм</label><input type="text" id="nuTg" placeholder="@nickname" autocomplete="off"></div></div>'+
      '<div class="row"><div><label for="nuMail">Почта</label>'+
        '<input type="email" id="nuMail" placeholder="maria@example.com" autocomplete="off"></div>'+
      '<div><label for="nuAllowed">Консультаций положено</label>'+
        '<input type="number" id="nuAllowed" min="0" max="99" placeholder="из курса"></div></div>'+
      '<div class="field-hint">Достаточно одного способа связи. Если оставить поле «положено» пустым, '+
      'возьмём число из курса. Ученица найдёт себя по той же почте или телефону, когда будет записываться.</div>'+
      '<div style="height:12px"></div><button class="btn btn-sm" data-a="cliAdd">Завести ученицу</button></div>';
  }

  if(dupes.length) h+='<div class="note warn" style="margin-top:16px"><b>Похоже, есть задвоенные карточки.</b> '+
    'Один человек попал в систему дважды — пока карточки порознь, история и запас встреч делятся пополам. '+
    '<button class="btn btn-sm" data-a="dupesOpen" style="margin-left:8px">Проверить дубли</button></div>';
  h+='</div>';

  if(!vse.length) return h+'<div class="card"><div class="empty">Пока никого нет. Заведите первую ученицу или дождитесь записи.</div></div>';

  h+='<div class="card">'+filtrUchenic({items:vse, search:S.cliSearch, kurs:kurs,
      act:'cliKurs', inputId:'cliSearch', hint:'Поиск по фамилии, имени, телефону или почте'});

  /* Второй ряд — состояние, а не курс. Куратору нужны те, у кого кончился
     запас, закрыт доступ или карточка задвоилась. */
  h+='<div class="chips" style="margin:2px 0 4px">'+
    [['','Все'],['out','Запас кончился'],['blocked','Закрыт доступ'],['dupe','Возможно дубль']].map(function(f){
      return '<button class="chip" aria-pressed="'+(srez===f[0])+'" data-a="cliFilter" data-v="'+f[0]+'">'+f[1]+'</button>';
    }).join('')+'</div>';

  var CL=vse.filter(function(c){
    if(!match(S.cliSearch, hay(c.name, c.fio, c.contact, c.phone, c.tg, c.email, c.course, c.courses))) return false;
    if(!poKursu(c, kurs)) return false;
    if(srez==='out')     return c.allowed>0 && c.used>=c.allowed;
    if(srez==='blocked') return !!(+c.blocked);
    if(srez==='dupe')    return (c.dupe||[]).length>0;
    return true;
  });
  if(!CL.length) return h+'<div class="empty">Никого не нашли. Проверьте написание или снимите фильтры.</div></div>';
  if(S.cliSearch||srez||kurs)
    h+='<div class="field-hint" style="margin-bottom:10px">Нашлось: <b>'+CL.length+'</b> из '+vse.length+'.</div>';

  /* Показываем первую сотню: дальше страница становится тяжёлой, а искать
     всё равно удобнее поиском, чем прокруткой. */
  var vsego=CL.length, obrez=false;
  if(!S.cliAll && vsego>100){ CL=CL.slice(0,100); obrez=true; }

  h+='<div class="scroll"><table><tr><th>Ученица</th><th>Связь</th><th>Курс</th>'+
     '<th class="num">Было</th><th class="num">Положено</th><th></th></tr>';
  CL.forEach(function(c){
    var out=c.allowed>0 && c.used>=c.allowed;
    var svyaz=[c.phone?telVid(c.phone):'', c.tg?'@'+c.tg:'', c.email].filter(Boolean);
    if(!svyaz.length && c.contact && c.contact!=='—') svyaz=[c.contact];
    h+='<tr><td><b>'+esc(c.fio||c.name)+'</b>'+
         (+c.blocked?'<div><span class="tag bad">закрыт доступ</span></div>':'')+
         ((c.dupe||[]).length?'<div><span class="tag wait">возможно дубль</span></div>':'')+'</td>'+
       '<td class="mono">'+(svyaz.length?svyaz.map(esc).join('<br>'):'<span class="muted">не знаем</span>')+'</td>'+
       '<td><select data-f="course" data-id="'+c.id+'" style="margin:0;min-width:150px">'+
         '<option value="">— не указан —</option>'+
         Object.keys(kursy).map(function(k){
           return '<option value="'+esc(k)+'"'+(c.course===k?' selected':'')+'>'+esc(k)+'</option>';
         }).join('')+
         /* Курс из старой таблицы может не совпасть со списком — не теряем его */
         (c.course && !(c.course in kursy) ? '<option value="'+esc(c.course)+'" selected>'+esc(c.course)+'</option>' : '')+
         '</select>'+
         (!c.course && c.courses?'<div class="field-hint" style="margin:4px 0 0">со слов ученицы: '+esc(c.courses)+'</div>':'')+'</td>'+
       '<td class="num">'+c.used+(out?'<div><span class="tag bad">кончились</span></div>':'')+'</td>'+
       '<td class="num"><input type="number" min="0" max="99" style="width:70px;margin:0" '+
         'data-f="allowed" data-id="'+c.id+'" value="'+(c.allowed||0)+'"></td>'+
       '<td style="white-space:nowrap"><button class="btn-ghost btn-sm" data-a="cliSave" data-id="'+c.id+'">Сохранить</button> '+
       '<button class="btn-link" data-a="cliEdit" data-id="'+c.id+'">'+(String(S.cliEdit)===String(c.id)?'скрыть':'изменить')+'</button> '+
       '<button class="btn-link" data-a="cliCard" data-id="'+c.id+'">история</button>'+
       ' <button class="btn-link" data-a="cliDel" data-id="'+c.id+'" style="color:var(--alarm)">удалить</button>'+
       '</td></tr>'+
       /* Правка данных: куратор дописывает связь, и если у ученицы есть
          аккаунт с этой почтой — всё это появится у неё в кабинете. */
       (String(S.cliEdit)===String(c.id)
        ? '<tr><td colspan="6" style="background:var(--sand);padding:14px 12px">'+
          '<div class="row"><div><label>Фамилия и имя</label>'+
          '<input type="text" data-f="eName" data-id="'+c.id+'" value="'+esc(c.name)+'"></div>'+
          '<div><label>Телефон</label>'+
          '<input type="tel" data-f="ePhone" data-id="'+c.id+'" value="'+esc(c.phone?telVid(c.phone):'')+'" placeholder="+7 999 123-45-67"></div></div>'+
          '<div class="row"><div><label>Telegram</label>'+
          '<input type="text" data-f="eTg" data-id="'+c.id+'" value="'+(c.tg?'@'+esc(c.tg):'')+'" placeholder="@nickname"></div>'+
          '<div><label>Почта</label>'+
          '<input type="email" data-f="eMail" data-id="'+c.id+'" value="'+esc(c.email||'')+'" placeholder="uchenica@example.com"></div></div>'+
          /* Курс и число встреч дублируем здесь: кураторы искали их именно
             в «изменить», а не в маленьком поле в строке таблицы */
          '<div class="row"><div><label>Курс</label>'+
          '<select data-f="eCourse" data-id="'+c.id+'">'+
            '<option value="">— не указан —</option>'+
            Object.keys(kursy).map(function(k){
              return '<option value="'+esc(k)+'"'+(c.course===k?' selected':'')+'>'+esc(k)+'</option>';
            }).join('')+
            (c.course && !(c.course in kursy)?'<option value="'+esc(c.course)+'" selected>'+esc(c.course)+'</option>':'')+
          '</select></div>'+
          '<div><label>Встреч положено (использовано: '+c.used+')</label>'+
          '<input type="number" min="0" max="99" data-f="eAllowed" data-id="'+c.id+'" value="'+(c.allowed||0)+'"></div></div>'+
          '<div class="field-hint">Число встреч главнее курса: что впишете здесь, то и действует. '+
          'Нет нужного курса — добавьте его в разделе «Курсы».</div>'+
          '<label>Заметка куратора</label>'+
          '<input type="text" data-f="eNote" data-id="'+c.id+'" value="'+esc(c.note||'')+'" placeholder="видна только кураторам">'+
          '<div class="field-hint">Почта — главный идентификатор: по ней карточка связывается с кабинетом ученицы, '+
          'и всё вписанное здесь она увидит у себя в профиле.</div>'+
          '<div style="height:10px"></div>'+
          '<button class="btn btn-sm" data-a="cliEditSave" data-id="'+c.id+'">Сохранить данные</button></td></tr>'
        : '');
  });
  h+='</table></div>';
  if(obrez) h+='<div style="margin-top:12px"><button class="btn-ghost btn-sm" data-a="cliAll">'+
    'Показать всех ('+vsego+')</button></div>';
  return h+'</div>';
}

/* Заявки. Ссылку на запись легко переслать кому угодно, поэтому запись
   становится настоящей только после подтверждения куратора. Время при
   этом уже держится за ученицей — торопиться на минуты не нужно. */
/* Встречи, до которых ссылка так и не дошла. Подтвердить запись можно и
   без неё — но тогда кто-то должен вспомнить и вписать, иначе ученица
   придёт в назначенный час и не будет знать, куда. */
function bezSsylki(){
  return (S.adm.bookings||[]).filter(function(b){
    return b.status==='booked' && !isPast(b.dt) && !String(b.link||'').trim();
  }).sort(function(a,b){ return a.dt<b.dt?-1:1; });
}
function kartochkaBezSsylki(){
  var N=bezSsylki();
  if(!N.length) return '';
  return '<div class="card"><div class="note warn"><b>Без ссылки на встречу: '+N.length+'.</b> '+
    'Ученицам обещано «пришлём отдельно». Вставьте ссылку — или попросите специалиста сделать это у себя.</div>'+
    '<div class="scroll"><table style="margin-top:6px"><tr><th>Когда</th><th>Ученица</th><th>Специалист</th><th></th></tr>'+
    N.map(function(b){
      return '<tr><td>'+esc(fmtFull(b.dt))+'</td><td>'+esc(b.client_name)+'</td>'+
        '<td>'+esc(b.ename||'—')+'</td>'+
        '<td style="white-space:nowrap"><div style="display:flex;gap:8px">'+
        '<input type="text" id="lk'+b.id+'" style="margin:0;min-width:200px" placeholder="https://…">'+
        '<button class="btn btn-sm" data-a="setLink" data-id="'+b.id+'">Сохранить</button></div></td></tr>';
    }).join('')+'</table></div></div>';
}

function aPending(){
  var D=S.adm, P=(D.bookings||[]).filter(function(b){return b.status==='pending';});
  var h=kartochkaBezSsylki();
  h+='<div class="card"><h3>Заявки от учениц</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Время за ученицей уже держится — другой на него не запишется. '+
    'Подтвердить можно и без ссылки: ученице напишем «пришлём отдельно», а ссылку впишете вы или специалист.</p>';
  if(!P.length) return h+'<div class="empty">Новых заявок нет.</div></div>';
  P.sort(function(a,b){ return a.dt<b.dt?-1:1; });
  P.forEach(function(b){
    h+='<div style="border-top:1px solid var(--line);padding-top:16px;margin-top:16px">'+
      '<div class="split"><div><b>'+esc(fmtFull(b.dt))+'</b> — '+esc(b.client_name)+'</div>'+
      '<span class="tag wait">ждёт подтверждения</span></div>'+
      '<div class="muted" style="margin-top:6px">'+esc(b.contact||'—')+
        (b.email?' · '+esc(b.email):'')+(b.course?' · курс: '+esc(b.course):'')+
        ' · специалист: '+esc(b.ename||'—')+'</div>'+
      (realNote(b.note,b.client_name)?'<div class="note" style="margin-top:10px">Запрос: '+esc(realNote(b.note,b.client_name))+'</div>':'')+
      '<label style="margin-top:12px">Ссылка на встречу — по желанию</label>'+
      '<input type="text" data-f="admlink" data-id="'+b.id+'" placeholder="Вставьте ссылку, если хотите свою">'+
      '<div class="field-hint">Оставьте пустым — система возьмёт ссылку из запаса специалиста. Ученице она уйдёт на почту и будет видна по коду записи.</div>'+
      '<label>Комментарий для специалиста — по желанию</label>'+
      '<input type="text" data-f="admnote" data-id="'+b.id+'" placeholder="Например: третья сессия, тяжёлое состояние">'+
      '<div class="row" style="margin-top:6px">'+
        '<button class="btn btn-sm" data-a="bookOk" data-id="'+b.id+'">Подтвердить</button>'+
        '<button class="btn-ghost btn-sm" data-a="bookNo" data-id="'+b.id+'">Отклонить</button>'+
      '</div></div>';
  });
  return h+'</div>';
}

/* Лента по ученице: у кого была, чем закончилось, что писал куратор.
   Это внутренний контур — если у ученицы что-то пойдёт не так, важно
   видеть всю её историю, а не одну встречу. */
function aClientCard(){
  var K=S.clientCard;
  if(!K) return '';
  var c=K.client;
  var h='<div class="card"><div class="split"><div><div class="eyebrow">Ученица</div><h3>'+esc(c.name)+'</h3></div>'+
    '<button class="btn-ghost btn-sm" data-a="cliClose">Закрыть</button></div>'+
    /* Связь целиком: телефон, телеграм и почта живут в своих полях,
       а старый «контакт» у перенесённых карточек — часто прочерк */
    '<div class="muted" style="margin-top:8px">'+
      (function(){
        var sv=[c.phone?telVid(c.phone):'', c.tg?'@'+c.tg:'', c.email||''].filter(Boolean);
        if(!sv.length && c.contact && c.contact!=='—') sv=[c.contact];
        return (sv.length?sv.map(esc).join(' · '):'связи нет')+(c.course?' · курс: '+esc(c.course):'');
      })()+'</div>'+
    '<div class="metrics" style="margin-top:16px">'+
      metric(c.used,'Встреч всего')+
      metric(c.allowed?(c.allowed-c.used):'—','Осталось по курсу',c.allowed>0&&c.used>=c.allowed)+
      metric(K.history.filter(function(x){return x.status==='noshow';}).length,'Не пришла')+
    '</div>';
  if(+c.blocked) h+='<div class="note warn"><b>Записываться нельзя.</b>'+(c.blocked_why?' '+esc(c.blocked_why):'')+'</div>';
  h+='<label for="cliNote">Заметка куратора — видят куратор и специалист</label>'+
     '<input type="text" id="cliNote" value="'+esc(c.note||'')+'" placeholder="Например: ушла на возврат, вернулась в июне">'+
     '<div class="row" style="margin-top:8px">'+
       '<button class="btn-ghost btn-sm" data-a="cliNoteSave" data-id="'+c.id+'">Сохранить заметку</button>'+
       '<button class="btn-ghost btn-sm" data-a="cliBlock" data-id="'+c.id+'" data-on="'+(+c.blocked?'0':'1')+'">'+
         (+c.blocked?'Разрешить записываться':'Закрыть запись')+'</button>'+
     '</div></div>';

  var others=(K.dupes||[]);
  if(others.length){
    h+='<div class="card"><h3>Похоже, это один и тот же человек</h3>'+
      '<p class="muted" style="margin:8px 0 14px">Та же ученица с другим контактом. После склейки её встречи, '+
      'запас и заметки соберутся в одной карточке — лишняя исчезнет. Отменить склейку нельзя, но данные не теряются.</p>';
    others.forEach(function(o){
      h+='<div class="split" style="border-top:1px solid var(--line);padding-top:12px;margin-top:12px">'+
        '<div><b>'+esc(o.name)+'</b><div class="muted">'+esc(o.contact||'контакта нет')+
        ' · встреч: '+o.used+(o.course?' · '+esc(o.course):'')+'</div></div>'+
        '<button class="btn-ghost btn-sm" data-a="cliMerge" data-id="'+c.id+'" data-from="'+o.id+'">Склеить сюда</button></div>';
    });
    h+='</div>';
  }
  h+='<div class="card"><h3>История встреч</h3>';
  if(!K.history.length) h+='<div class="empty">Встреч пока не было.</div>';
  K.history.forEach(function(b){
    h+='<div style="border-top:1px solid var(--line);padding-top:12px;margin-top:12px">'+
      '<div class="split"><div><b>'+esc(fmtFull(b.dt))+'</b> — '+esc(b.expert||'—')+'</div>'+statusTag(b)+'</div>'+
      (realNote(b.note,c.name)?'<div class="muted" style="margin-top:6px">Запрос: '+esc(realNote(b.note,c.name))+'</div>':'')+
      (b.adm_note?'<div class="muted" style="margin-top:4px">Куратор: '+esc(b.adm_note)+'</div>':'')+
      (b.rep_plan?'<div class="muted" style="margin-top:4px">Специалист: '+esc(b.rep_plan)+'</div>':'')+
      '</div>';
  });
  return h+'</div>';
}

function aWaiting(){
  var W=(S.adm&&S.adm.waiting)||[];
  var h='<div class="card"><h3>Ждут свободного времени</h3>'+
    '<p class="muted" style="margin:8px 0 14px">Как только специалист откроет подходящий час, система пишет им сама — '+
    'не чаще раза в сутки на человека. Время никому не бронируется: записывается та, кто успела первой.</p>';
  if(!W.length) return h+'<div class="empty">Очереди нет — свободного времени хватает всем.</div></div>';
  h+='<div class="scroll"><table><tr><th>Кто</th><th>Связь</th><th>Тема</th><th>Ждёт с</th><th>Позвали</th><th></th></tr>';
  W.forEach(function(w){
    h+='<tr><td><b>'+esc(w.name)+'</b>'+(+w.pair?' <span class="tag">пара</span>':'')+'</td>'+
       '<td class="mono">'+esc(w.contact)+(w.email?'<br><span class="muted">'+esc(w.email)+'</span>':'')+'</td>'+
       '<td>'+esc(w.topic||'—')+'</td>'+
       '<td>'+esc(fmtDay(w.created_at))+'</td>'+
       '<td>'+(w.notified_at?esc(fmtDay(w.notified_at)):'<span class="tag wait">ещё нет</span>')+'</td>'+
       '<td><button class="btn-ghost btn-sm" data-a="waitDel" data-v="'+w.id+'">Убрать</button></td></tr>';
  });
  return h+'</table></div></div>';
}

/* Периоды дашборда. Раньше всё считалось за всё время разом: сравнить
   август с июлем было нельзя, хотя работа идёт месяцами. */
function iso(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }

function periods(){
  var now=new Date();
  var m0=new Date(now.getFullYear(), now.getMonth(), 1);
  var m1=new Date(now.getFullYear(), now.getMonth()+1, 0);
  var p0=new Date(now.getFullYear(), now.getMonth()-1, 1);
  var p1=new Date(now.getFullYear(), now.getMonth(), 0);
  return {
    d30:   {t:'30 дней',       d1:iso(new Date(now-30*864e5)), d2:iso(now)},
    month: {t:'Этот месяц',    d1:iso(m0), d2:iso(m1)},
    prev:  {t:'Прошлый месяц', d1:iso(p0), d2:iso(p1)},
    all:   {t:'За всё время',  d1:'',      d2:''}
  };
}
/* Границы периода: либо выбранная заготовка, либо свои даты из календаря. */
function periodRange(){
  var P=periods();
  if(S.period==='own') return {t:'Свой промежуток', d1:S.pFrom||'', d2:S.pTo||''};
  var p=P[S.period]||P.d30;
  return {t:p.t, d1:p.d1, d2:p.d2};
}

/* Отказы живут на сервере, поэтому под выбранный период их надо
   перезапросить — иначе сводка считалась бы за свои 30 дней. */
function loadRefusals(){
  var r=periodRange();
  return api('refusals',{from:r.d1||'',to:r.d2||''},'admin')
    .then(function(j){ S.refusals=j; render(); })
    .catch(function(){});
}

function aDash(){
  var D=S.adm;
  var P=periods(), pk=S.period||'d30', per=periodRange();
  var inPeriod=function(b){
    if(per.d1 && b.dt < per.d1+' 00:00:00') return false;
    if(per.d2 && b.dt > per.d2+' 23:59:59') return false;
    return true;
  };
  var Ball=D.bookings.filter(function(b){return b.status!=='cancelled';});
  var B=Ball.filter(inPeriod);
  var staff=!!(D.settings&&D.settings.staff);
  var due=B.filter(function(b){return isPast(b.dt);});
  var held=B.filter(function(b){return b.status==='held';});
  var noshow=B.filter(function(b){return b.status==='noshow';});
  var csat=B.filter(function(b){return b.csat;});
  var paid=B.filter(function(b){return b.paid==1;});
  var sum=paid.reduce(function(a,b){return a+(Number(b.paid_sum)||0);},0);
  var avg=csat.length?(csat.reduce(function(a,b){return a+Number(b.csat);},0)/csat.length).toFixed(1):'—';
  var npsArr=B.filter(function(b){return b.nps!==null&&b.nps!==undefined&&b.nps!=='';});
  var nps=npsArr.length?Math.round(npsArr.reduce(function(a,b){return a+Number(b.nps);},0)/npsArr.length):'—';
  var showRate=(held.length+noshow.length)?Math.round(held.length/(held.length+noshow.length)*100)+'%':'—';

  /* Наверх дашборда — то, что сгорит, если не заметить сегодня */
  var h=kartochkaBezSsylki();
  h+=blokDni(D.svodka, false);
  h+='<div class="card"><div class="eyebrow">Период</div>'+
    '<h3>'+esc(per.t)+(per.d1||per.d2?' <span class="muted" style="font-size:14px">'+esc(per.d1||'начало')+' — '+esc(per.d2||'сегодня')+'</span>':'')+'</h3>'+
    '<div class="chips" style="margin-top:12px">'+
    Object.keys(P).map(function(k){
      return '<button class="chip" aria-pressed="'+(k===pk)+'" data-a="period" data-v="'+k+'">'+esc(P[k].t)+'</button>';
    }).join('')+
    '<button class="chip" aria-pressed="'+(pk==='own')+'" data-a="period" data-v="own">Свой промежуток</button></div>'+
    '<div class="row" style="margin-top:12px"><div><label for="pFrom">С какого дня</label>'+
      '<input type="date" id="pFrom" value="'+esc(S.pFrom||'')+'"></div>'+
      '<div><label for="pTo">По какой</label><input type="date" id="pTo" value="'+esc(S.pTo||'')+'"></div></div>'+
    '<p class="muted" style="margin:0">Цифры ниже — за выбранный период. «Команда» и норма всегда считаются за текущий месяц.</p></div>';

  h+='<div class="metrics">'+
    metric(D.times.length,'Свободных слотов')+
    metric(B.length,'Записей')+
    metric(held.length,'Встреч прошло')+
    metric(showRate,'Доходимость',held.length+noshow.length>0&&held.length/(held.length+noshow.length)<0.75)+
    /* Пока ни одна ученица не оценила встречу, три пустых плитки про оценки
       только сбивают с толку — показываем их, когда есть что показывать. */
    (csat.length
      ? metric(csat.length+' / '+Math.max(due.length,csat.length),'Оценок собрано')+
        metric(avg,'Средняя оценка')+
        (npsArr.length?metric(nps,'Готовность советовать'):'')
      : '')+
    (staff?'':metric(paid.length,'Оплат'))+
    '</div>'+
    (csat.length?'':'<div class="field-hint" style="margin:-4px 0 14px">Оценок за период нет: '+
      'ученицы ставят их после встречи, а просьбу можно отправить во вкладке «Рассылка».</div>');

  if(!staff) h+='<div class="card"><div class="split"><h3>Выручка по подтверждённым оплатам</h3>'+
     '<div style="font-family:Prata,serif;font-size:26px">'+sum.toLocaleString('ru-RU')+' ₽</div></div></div>';

  /* Разбивка по курсам: курс спрашиваем у каждой, а в цифрах он до сих пор
     не участвовал — хотя именно по нему считают нагрузку и планируют. */
  var byCourse={};
  B.forEach(function(b){
    var k=b.course||'не указан';
    if(!byCourse[k]) byCourse[k]={n:0,held:0,no:0,who:{}};
    byCourse[k].n++;
    if(b.status==='held') byCourse[k].held++;
    if(b.status==='noshow') byCourse[k].no++;
    if(b.ckey) byCourse[k].who[b.ckey]=1;
  });
  var courseKeys=Object.keys(byCourse).sort(function(a,b){return byCourse[b].n-byCourse[a].n;});
  if(courseKeys.length){
    h+='<div class="card"><h3>По курсам</h3><p class="muted" style="margin:8px 0 14px">'+
      'Сколько консультаций съедает каждый курс и как по нему доходят.</p>'+
      '<div class="scroll"><table><tr><th>Курс</th><th class="num">Встреч</th><th class="num">Учениц</th>'+
      '<th class="num">Провели</th><th class="num">Не пришли</th><th class="num">Доходимость</th></tr>';
    courseKeys.forEach(function(k){
      var c=byCourse[k], d=(c.held+c.no)?Math.round(c.held/(c.held+c.no)*100)+'%':'—';
      h+='<tr><td>'+esc(k)+'</td><td class="num">'+c.n+'</td><td class="num">'+Object.keys(c.who).length+'</td>'+
        '<td class="num">'+c.held+'</td><td class="num">'+(c.no||'—')+'</td><td class="num"><b>'+d+'</b></td></tr>';
    });
    h+='</table></div></div>';
  }

  /* Отказы: сколько раз ученицы не смогли записаться и почему. Это ответ
     на вопрос «сколько часов открывать» — единственный, которого раньше
     не было ни в одной цифре. */
  var R=S.refusals||D.refusals||{by:{},total:0,names:{}};
  h+='<div class="card"><div class="split"><h3>Не смогли записаться</h3>'+
    '<div style="font-family:Charter,Georgia,serif;font-size:26px">'+(R.total||0)+'</div></div>'+
    '<p class="muted" style="margin:8px 0 0">За выбранный период. Каждый случай — ученица, которая пришла записаться и ушла ни с чем.</p>';
  if(!R.total) h+='<div class="empty" style="margin-top:12px">Пока никто не упирался — либо всем хватает времени, либо до записи ещё не дошли.</div>';
  else {
    h+='<div class="scroll" style="margin-top:14px"><table><tr><th>Почему</th><th class="num">Раз</th></tr>';
    Object.keys(R.by).sort(function(a,b){return R.by[b]-R.by[a];}).forEach(function(k){
      h+='<tr><td>'+esc(R.names[k]||k)+'</td><td class="num"><b>'+R.by[k]+'</b></td></tr>';
    });
    h+='</table></div>';
    /* Счётчик по причинам не говорит, КОГО развернули. Имена — то, с чем
       куратор может что-то сделать: открыть встречи, записать рукой. */
    if((R.rows||[]).length){
      h+='<div class="scroll" style="margin-top:14px"><table>'+
        '<tr><th>Когда</th><th>Ученица</th><th>Связь</th><th>Почему</th><th>Хотела на</th></tr>'+
        R.rows.slice(0,15).map(function(x){
          return '<tr><td class="muted">'+esc(fmtKratko(x.at))+'</td>'+
            '<td><b>'+esc(x.name||'—')+'</b></td>'+
            '<td class="mono">'+esc(x.contact||'—')+'</td>'+
            '<td>'+esc(R.names[x.why]||x.why)+(x.detail?' <span class="muted">'+esc(x.detail)+'</span>':'')+'</td>'+
            '<td class="muted">'+(x.dt?esc(fmtFull(x.dt)):'—')+'</td></tr>';
        }).join('')+'</table></div>';
      if((R.rows||[]).length>15)
        h+='<div class="field-hint" style="margin-top:8px">Показаны 15 последних из '+R.rows.length+'.</div>';
    }
    if((R.by.no_time||0)+(R.by.taken||0) > 0)
      h+='<div class="field-hint" style="margin-top:10px">Из них '+((R.by.no_time||0)+(R.by.taken||0))+
         ' — про нехватку времени. Это и есть та цифра, по которой понятно, сколько часов открывать.</div>';
  }
  h+='</div>';

  /* Пустой запас ссылок всплывает в худший момент — когда куратор уже
     подтверждает заявку. Показываем это заранее, на первом экране. */
  var dry=(D.experts||[]).filter(function(e){return e.active && (e.linksFree||0)<3;});
  if(dry.length) h+='<div class="note warn"><b>Кончаются ссылки на встречи.</b> '+
    dry.map(function(e){return esc(e.name)+' — '+(e.linksFree||0);}).join(', ')+
    '. Пока запас пуст, подтвердить заявку не получится: ученице нечего будет отправить. '+
    'Попросите пополнить в кабинете или вставляйте свою ссылку при подтверждении.</div>';

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
      metric(T.withHeld,'Уже провели встречи')+
      metric(T.held,'Встреч проведено')+
      metric((staff?(T.mOwe||0):T.owe).toLocaleString('ru-RU')+' ₽',staff?'К выплате за месяц':'К выплате')+
      '</div>';
    h+='<p class="muted" style="margin:0 0 12px">Ставка школы — <b>'+T.rate.toLocaleString('ru-RU')+' ₽</b> за проведённую консультацию, '+
      'одинаковая для всех. Неявки и отмены не считаются.'+(staff?' Считаем за текущий месяц — перенесённая история в выплату не идёт.':'')+' Изменить — в Настройках.</p>';
    if(T.rows.length){
      var norm=T.norm||0;
      h+='<div class="scroll"><table><tr><th>Специалист</th>'+
         (norm?'<th class="num">Выставила часов</th><th class="num">Провела</th><th class="num">Не пришли</th><th class="num">До нормы</th>':'')+
         '<th class="num">Доходимость</th><th class="num">Оценка</th>'+
         '<th class="num">'+(staff?'К выплате за месяц':'К выплате')+'</th>'+(staff?'':'<th class="num">Ученицы оплатили</th><th class="num">Своя цена</th>')+'<th>TG</th></tr>';
      T.rows.forEach(function(r){
        /* «До нормы» — сколько встреч ещё нужно, считая уже назначенные впереди.
           Неявки нормой не засчитываются, поэтому их приходится довыставлять. */
        var left=norm?Math.max(0,norm-(r.mHeld||0)-(r.mAhead||0)):0;
        /* Доходимость и оценку считаем по всем встречам специалиста — так видно,
           не сыплется ли качество у того, кто закрывает норму. */
        var bb=D.bookings.filter(function(b){ return b.expert_id==r.id && b.status!=='cancelled'; });
        var hd=bb.filter(function(b){return b.status==='held';}).length;
        var ns=bb.filter(function(b){return b.status==='noshow';}).length;
        var cs=bb.filter(function(b){return b.csat;});
        h+='<tr><td><b>'+esc(r.name)+'</b></td>'+
          (norm?'<td class="num">'+(r.mSlots||0)+'</td>'+
                '<td class="num">'+(r.mHeld||0)+'</td>'+
                '<td class="num'+((r.mNo||0)?' ':'')+'">'+((r.mNo||0)||'—')+'</td>'+
                '<td class="num">'+(left?'<b>'+left+'</b>':'<span class="tag ok">норма</span>')+'</td>':'')+
          '<td class="num">'+((hd+ns)?Math.round(hd/(hd+ns)*100)+'%':'—')+'</td>'+
          '<td class="num">'+(cs.length?(cs.reduce(function(a,x){return a+Number(x.csat);},0)/cs.length).toFixed(1):'—')+'</td>'+
          '<td class="num"><b>'+((staff?(r.mOwe||0):r.owe)?(staff?r.mOwe:r.owe).toLocaleString('ru-RU')+' ₽':'—')+'</b></td>'+
          (staff?'':'<td class="num">'+(r.paid?r.paid.toLocaleString('ru-RU')+' ₽':'—')+'</td>'+
                   '<td class="num muted">'+(r.own?r.own.toLocaleString('ru-RU')+' ₽':'—')+'</td>')+
          '<td>'+(r.tg?'<span class="tag ok">✓</span>':'<span class="tag bad">нет</span>')+'</td></tr>';
      });
      h+='</table></div>';
      if(norm) h+='<div class="field-hint" style="margin-top:10px">Норма — <b>'+norm+'</b> проведённых встреч в месяц. '+
        '«До нормы» учитывает уже назначенные впереди встречи; неявки в норму не идут, их нужно довыставлять. Изменить норму — в Настройках.</div>';
    }
    h+='<div class="field-hint" style="margin-top:10px">«К выплате» — проведённые встречи × ставка школы. '+
       '«Доходимость» — сколько записей дошло до встречи. «Оценка» — средний балл от учениц.</div></div>';
  }

  var H=D.history||[];
  if(H.length){
    h+='<div class="card"><h3>История изменений</h3><p class="muted" style="margin:8px 0 12px">Замены специалистов, переносы и отмены.</p>'+
      '<div class="scroll"><table><tr><th>Когда</th><th>Что</th><th>Ученица</th><th>Код</th></tr>';
    H.slice(0,15).forEach(function(x){
      var kind={expert:'<span class="tag bad">замена</span>',move:'<span class="tag wait">перенос</span>',cancel:'<span class="tag">отмена</span>'}[x.kind]||'';
      h+='<tr><td class="muted">'+esc(String(x.at).slice(5,16))+'</td><td>'+kind+' '+esc(x.txt)+'</td>'+
         '<td>'+esc(x.client_name||'—')+'</td><td>'+esc(x.code||'')+'</td></tr>';
    });
    h+='</table></div></div>';
  }

  return h;
}

function aBookings(){
  var D=S.adm, B=D.bookings;
  var expById={};
  D.experts.forEach(function(e){ expById[e.id]=e; });

  var h='';
  h+='<div class="card"><div class="split"><h3>Журнал записей</h3>'+
    '<div style="display:flex;gap:8px;flex-wrap:wrap">'+
    '<span class="muted" style="align-self:center;font-size:13px">Скачать в Excel:</span>'+
    '<button class="btn-ghost btn-sm" data-a="csv">записи</button>'+
    '<button class="btn-ghost btn-sm" data-a="csvCli">ученицы</button>'+
    '<button class="btn-ghost btn-sm" data-a="csvPay">выплаты</button>'+
    '<button class="btn-ghost btn-sm" data-a="csvWait">очередь</button>'+
    '</div></div><div style="height:14px"></div>';
  if(!B.length) return h+'<div class="empty">Записей ещё нет.</div></div>';
  /* Фильтры журнала: поиск по фамилии, специалист, день или промежуток.
     Куратор смотрит записи по-разному — «что у Ирины на этой неделе»
     и «когда была Иванова» это два разных вопроса. */
  h+='<input type="text" id="bookSearch" placeholder="Поиск по фамилии, связи, коду или курсу" value="'+esc(S.bookSearch||'')+'" autocomplete="off">'+
    '<div class="row">'+
      '<div><label for="fExp">Специалист</label><select id="fExp"><option value="">все</option>'+
        D.experts.filter(function(e){return e.active;}).map(function(e){
          return '<option value="'+e.id+'"'+(String(S.fExp)===String(e.id)?' selected':'')+'>'+esc(e.name)+'</option>';
        }).join('')+'</select></div>'+
      '<div><label for="fStatus">Статус</label><select id="fStatus">'+
        [['','любой'],['pending','ждёт подтверждения'],['booked','запланирована'],
         ['held','состоялась'],['noshow','не пришла'],['cancelled','отменена']].map(function(o){
          return '<option value="'+o[0]+'"'+(S.fStatus===o[0]?' selected':'')+'>'+o[1]+'</option>';
        }).join('')+'</select></div>'+
    '</div>'+
    '<div class="row">'+
      '<div><label for="fFrom">С какого дня</label><input type="date" id="fFrom" value="'+esc(S.fFrom||'')+'"></div>'+
      '<div><label for="fTo">По какой</label><input type="date" id="fTo" value="'+esc(S.fTo||'')+'"></div>'+
    '</div>'+
    '<div class="chips" style="margin-bottom:14px">'+
      '<button class="chip" data-a="fQuick" data-v="today">Сегодня</button>'+
      '<button class="chip" data-a="fQuick" data-v="week">Неделя вперёд</button>'+
      '<button class="chip" data-a="fQuick" data-v="month">Этот месяц</button>'+
      '<button class="chip" data-a="fQuick" data-v="clear">Сбросить всё</button>'+
    '</div>';

  var Bq, arch=false;
  if(S.bookFound){ Bq=S.bookFound; arch=true; }
  else Bq=B;

  /* Курсы — такой же срез, как специалист и статус: «покажи всё по семейным».
     Счётчик считаем по тому, что уже отобрано остальными фильтрами, иначе
     цифра в чипсе спорит с цифрой под таблицей. */
  var doKursa=Bq.filter(function(b){
    if(S.fExp && String(b.expert_id)!==String(S.fExp)) return false;
    if(S.fStatus && b.status!==S.fStatus) return false;
    if(S.fFrom && b.dt < S.fFrom+' 00:00:00') return false;
    if(S.fTo   && b.dt > S.fTo+' 23:59:59') return false;
    return match(S.bookSearch, hay(b.client_name, b.contact, b.phone, b.tg_nick, b.email, b.code, b.ename, b.course));
  });
  var poKursam={}, bezKursa=0;
  doKursa.forEach(function(b){
    var k=String(b.course||'').trim();
    if(k) poKursam[k]=(poKursam[k]||0)+1; else bezKursa++;
  });
  var spisokK=Object.keys(poKursam).sort(function(a,c){ return poKursam[c]-poKursam[a] || a.localeCompare(c,'ru'); });
  if(spisokK.length || bezKursa){
    h+='<div class="chips" style="margin:0 0 12px">'+
      '<button class="chip" aria-pressed="'+(!S.fKurs)+'" data-a="fKurs" data-v="">Все курсы ('+doKursa.length+')</button>'+
      spisokK.map(function(k){
        return '<button class="chip" aria-pressed="'+(S.fKurs===k)+'" data-a="fKurs" data-v="'+esc(k)+'">'+
               esc(k)+' <b>'+poKursam[k]+'</b></button>';
      }).join('')+
      (bezKursa?'<button class="chip" aria-pressed="'+(S.fKurs==='—')+'" data-a="fKurs" data-v="—">Курс не указан <b>'+bezKursa+'</b></button>':'')+
      '</div>';
  }
  Bq=doKursa.filter(function(b){
    if(!S.fKurs) return true;
    var k=String(b.course||'').trim();
    return S.fKurs==='—' ? !k : k===S.fKurs;
  });
  var filtered=S.bookSearch||S.fExp||S.fStatus||S.fFrom||S.fTo||S.fKurs;
  if(filtered){
    h+='<div class="field-hint" style="margin-bottom:10px">Показано: '+Bq.length+
       (arch?' по всей истории.':' из '+B.length+' за два месяца.')+
       (!arch&&S.bookSearch&&(D.hidden||0)
          ? ' <button class="btn-link" data-a="findAll">Поискать в архиве — там ещё '+D.hidden+'</button>':'')+'</div>';
  }else if(D.hidden){
    h+='<div class="field-hint" style="margin-bottom:10px">Показаны записи за два месяца и всё, что впереди. Ещё '+D.hidden+
       ' в архиве — они найдутся поиском.</div>';
  }
  if(!Bq.length) return h+'<div class="empty">Никого не нашли. Проверьте написание или очистите поиск.</div></div>';
  h+='<div class="scroll"><table><tr><th>Когда</th><th>Ученица</th><th>Специалист</th><th>Статус</th><th></th></tr>';
  Bq.forEach(function(b){
    /* Строка отвечает на один вопрос: кто, когда, к кому и чем кончилось.
       Всё остальное — ссылка, запрос, код, действия и переписка — живёт
       в «Подробнее»: иначе таблица не читается, а нужное тонет. */
    /* В старых записях ник лежит одной строкой в контакте, а не в своём
       поле — если этого не учесть, у половины учениц «нет связи». */
    var nick = b.tgNick || (/^@[A-Za-z0-9_]{3,}$/.test(String(b.contact||'').trim())
                             ? String(b.contact).trim().slice(1) : '');
    var vk   = /vk\.(com|ru)\//i.test(String(b.contact||''));
    var kanal = b.tg_chat  ? '<span class="tag ok">бот пишет сам</span>'
              : b.email    ? '<span class="tag">почта</span>'
              : nick       ? '<span class="tag wait">Telegram вручную</span>'
              : b.phone    ? '<span class="tag wait">только телефон</span>'
              : vk         ? '<span class="tag wait">только ВКонтакте</span>'
                           : '<span class="tag bad">нет связи</span>';
    var svyaz=[];
    if(b.phone) svyaz.push(telVid(b.phone));
    if(nick) svyaz.push('@'+nick);
    if(b.email) svyaz.push(b.email);
    if(!svyaz.length && b.contact && b.contact!=='—') svyaz.push(b.contact);
    if(!svyaz.length) svyaz.push('—');
    var otkryt = String(S.histFor)===String(b.id);
    h+='<tr'+(otkryt?' style="background:var(--sand)"':'')+'>'+
      '<td>'+esc(fmtFull(b.dt))+'</td>'+
      '<td>'+esc(b.client_name)+
        '<div class="mono muted" style="margin-top:3px">'+svyaz.map(esc).join('<br>')+'</div>'+
        '<div style="margin-top:3px">'+kanal+'</div></td>'+
      '<td>'+esc(b.ename||'—')+
        (b.course?'<div class="muted" style="margin-top:3px">'+esc(b.course)+'</div>':'')+'</td>'+
      '<td>'+statusTag(b)+
        (b.status==='booked'&&!isPast(b.dt)
          ? '<br>'+(b.confirm==='yes'?'<span class="tag ok">подтвердила</span>'
             :b.confirm==='no'?'<span class="tag bad">не отвечает</span>'
             :'<span class="tag wait">не подтверждён</span>')
          : '')+
        (b.csat?'<div class="muted" style="margin-top:3px">оценка '+esc(b.csat)+' из 5</div>':'')+'</td>'+
      '<td style="white-space:nowrap"><button class="btn-ghost btn-sm" data-a="histFor" data-id="'+b.id+'">'+
        (otkryt?'Свернуть':'Подробнее')+'</button></td></tr>'+
      (otkryt ? histRow(b) : '');
  });
  return h+'</table></div></div>';
}

/* Раскрытая карточка записи: что за встреча, что с ней можно сделать
   и что по ней уже происходило. */
function histRow(b){
  var D=S.adm;

  /* ── что за встреча ── */
  var pole=function(nazv, znach){
    return znach ? '<tr><th style="width:170px;padding-right:14px">'+nazv+'</th><td>'+znach+'</td></tr>' : '';
  };
  var zapros=realNote(b.note,b.client_name);
  var kart='<table style="margin:0">'+
    pole('Код записи', esc(b.code)+(b.changed?' <span class="tag bad">была замена</span>':''))+
    pole('Курс', esc(b.course||''))+
    pole('Запрос', esc(b.category||'')+(zapros?' <span class="muted">· «'+esc(zapros)+'»</span>':''))+
    pole('Ссылка на встречу', (b.link
      ? '<a href="'+esc(b.link)+'" target="_blank" rel="noopener">'+esc(b.link)+'</a>'
      : '<span class="tag bad">пока нет</span>')+
      (b.status!=='cancelled' && !isPast(b.dt)
        ? '<div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">'+
          '<input type="text" id="lk'+b.id+'" style="margin:0;flex:1;min-width:220px" '+
          'placeholder="https://salutejazz.ru/calls/…" value="'+esc(b.link||'')+'">'+
          '<button class="btn-ghost btn-sm" data-a="setLink" data-id="'+b.id+'">'+
          (b.link?'Заменить':'Сохранить')+'</button></div>'+
          '<div class="field-hint" style="margin-top:4px">Как сохраните — ученице сразу уйдёт письмо со ссылкой.</div>'
        : ''))+
    pole('Отзыв ученицы', b.comment?'«'+esc(b.comment)+'»':'')+
    pole('Заметка куратора', esc(b.adm_note||''))+
    '</table>';

  /* ── что можно сделать ── */
  var mozhno='';
  if(b.status==='searching')
    mozhno+='<button class="btn btn-sm" data-a="swapStart" data-id="'+b.id+'">Назначить специалиста</button> ';
  else if(b.status!=='cancelled'){
    mozhno+='<button class="btn-ghost btn-sm" data-a="swapStart" data-id="'+b.id+'">Сменить специалиста</button> ';
    if(!isPast(b.dt)) mozhno+='<button class="btn-ghost btn-sm" data-a="unassign" data-id="'+b.id+'">Снять специалиста</button> ';
  }
  if(b.status!=='cancelled' && !isPast(b.dt)){
    mozhno+='<button class="btn-ghost btn-sm" data-a="admMove" data-id="'+b.id+'">'+
            (String(S.moveFor)===String(b.id)?'Не переносить':'Перенести встречу')+'</button> '+
            '<button class="btn-ghost btn-sm" data-a="reissue" data-id="'+b.id+'">Новая ссылка</button> '+
            '<button class="btn-ghost btn-sm" data-a="adminCancel" data-id="'+b.id+'">Отменить встречу</button> ';
  }
  mozhno+='<button class="btn-link" data-a="adminDelete" data-id="'+b.id+'" style="color:var(--alarm)">Удалить запись</button>';

  /* ── выбор нового времени ── */
  var perenos='';
  if(String(S.moveFor)===String(b.id)){
    var svob=(D.free||[]).filter(function(f){ return f.dt!==b.dt; });
    /* Сначала часы того же специалиста: перенос «к своему» — это правило. */
    var svoi=svob.filter(function(f){ return String(f.expert_id)===String(b.expert_id); });
    var chuzhie=svob.filter(function(f){ return String(f.expert_id)!==String(b.expert_id); });
    if(!svob.length){
      perenos='<div class="note warn">Свободных часов нет — попросите специалистов открыть время.</div>';
    }else{
      var opt=function(f){ return '<option value="'+esc(f.dt)+'">'+esc(fmtFull(f.dt))+' — '+esc(f.ename)+'</option>'; };
      perenos='<div style="border-top:1px solid var(--line);margin-top:14px;padding-top:14px">'+
        '<label for="mv'+b.id+'">Новое время</label>'+
        '<select id="mv'+b.id+'">'+
          (svoi.length?'<optgroup label="У того же специалиста">'+svoi.map(opt).join('')+'</optgroup>':'')+
          (chuzhie.length?'<optgroup label="У других — специалист сменится">'+chuzhie.map(opt).join('')+'</optgroup>':'')+
        '</select>'+
        '<div class="field-hint">Ученице уйдёт сообщение с новым временем, старый час освободится.</div>'+
        '<div style="height:10px"></div>'+
        '<button class="btn btn-sm" data-a="moveDo" data-id="'+b.id+'">Перенести</button></div>';
    }
  }

  /* ── что происходило ── */
  var L=S.histLog, lenta;
  if(!L) lenta='<div class="muted">Загружаю…</div>';
  else if(!L.length) lenta='<div class="muted">Пока ничего не происходило.</div>';
  else{
    lenta='<table style="margin:0">';
    L.forEach(function(x){
      lenta+='<tr><td style="width:130px;white-space:nowrap" class="muted">'+esc(fmtKratko(x.at))+'</td>'+
        '<td><b>'+esc(x.what)+'</b>'+(x.how?' <span class="muted">· '+esc(x.how)+'</span>':'')+'</td></tr>';
    });
    lenta+='</table>';
  }

  var blok=function(nazv, telo){
    return '<div style="margin-bottom:18px"><div class="eyebrow" style="margin-bottom:8px">'+nazv+'</div>'+telo+'</div>';
  };
  return '<tr><td colspan="5" style="background:var(--sand);padding:16px 12px">'+
    blok('Встреча', kart)+
    blok('Что можно сделать', mozhno+perenos)+
    blok('Что происходило', lenta)+
    '</td></tr>';
}

function aExperts(){
  var D=S.adm;
  var qq=(S.expSearch||'').trim().toLowerCase();
  var list=qq?D.experts.filter(function(e){return e.name.toLowerCase().indexOf(qq)>=0;}):D.experts;
  var can=(D.role==='owner')||(D.settings&&D.settings.experts_by==='admin');
  var norm=(D.team&&D.team.norm)||0;

  var h='<div class="card"><div class="eyebrow">Специалисты</div><h1>Кто ведёт встречи</h1>'+
    '<p class="muted" style="margin-top:10px">Здесь состояние каждого: сколько ссылок в запасе, сколько часов открыто, '+
    'сколько встреч провёл. «Скрыть» — для тех, кто больше не работает: они пропадут из выбора, а история останется.</p></div>';

  h+='<div class="card"><h3>В работе: '+D.experts.filter(function(e){return e.active;}).length+'</h3>'+
    (D.experts.length>6?'<input type="text" id="expSearch" placeholder="Поиск по имени" value="'+esc(S.expSearch||'')+'" autocomplete="off">':'');
  if(!D.experts.length) h+='<div class="empty">Пока никого. Добавьте специалиста ниже.</div>';
  else{
    h+='<div class="scroll"><table><tr><th>Имя</th><th>Кто это</th><th>Почта</th><th class="num">Ссылок</th>'+
       (norm?'<th class="num">За месяц</th>':'')+'<th class="num">Часов открыто</th><th class="num">Встреч всего</th>'+
       (can?'<th></th>':'')+'</tr>';
    list.forEach(function(e){
      var row=(D.team&&D.team.rows||[]).filter(function(r){return r.name===e.name;})[0]||{};
      h+='<tr><td><b>'+esc(e.name)+'</b>'+(e.active?'':' <span class="tag">скрыт</span>')+'</td>'+
        '<td>'+(e.direction
          ? esc(e.direction)+' <button class="btn-link" data-a="expDir" data-id="'+e.id+'" data-v="'+esc(e.direction)+'">сменить</button>'
          : '<button class="btn-link" data-a="expDir" data-id="'+e.id+'" data-v="">указать</button>')+'</td>'+
        '<td>'+(e.email
          ? '<span class="mono" style="font-size:12px">'+esc(e.email)+'</span> <button class="btn-link" data-a="expMail" data-id="'+e.id+'" data-v="'+esc(e.email)+'">сменить</button>'
          : '<button class="btn-link" data-a="expMail" data-id="'+e.id+'" data-v="">вписать</button> <span class="tag bad">нет</span>')+'</td>'+
        '<td class="num"><span class="tag'+((e.linksFree||0)===0?' bad':((e.linksFree||0)<5?' wait':' ok'))+'">'+(e.linksFree||0)+'</span> '+
          '<button class="btn-link" data-a="expLinks" data-id="'+e.id+'" data-v="'+esc(e.name)+'">пополнить</button></td>'+
        (norm?'<td class="num">'+(row.mHeld||0)+' из '+norm+
          (Math.max(0,norm-(row.mHeld||0)-(row.mAhead||0))?' <span class="tag wait">добрать '+Math.max(0,norm-(row.mHeld||0)-(row.mAhead||0))+'</span>':'')+'</td>':'')+
        '<td class="num">'+e.slots+'</td>'+
        '<td class="num">'+e.load+'</td>'+
        (can?'<td><button class="btn-link" data-a="toggleExpert" data-id="'+e.id+'">'+(e.active?'скрыть':'вернуть')+'</button></td>':'')+
        '</tr>';
    });
    h+='</table></div>';
  }
  h+='</div>';

  if(can){
    h+='<div class="card"><h3>Добавить специалиста</h3>'+
      '<p class="muted" style="margin:8px 0 14px">Заведите карточку, а человек потом зарегистрируется на сайте сам — '+
      'вы подтвердите доступ в разделе «Люди» и свяжете с этой карточкой.</p>'+
      '<div class="row"><div><label for="nName">Имя и фамилия</label><input type="text" id="nName" placeholder="Мария Иванова"></div>'+
      '<div><label for="nDir">Кто это</label><input type="text" id="nDir" list="nDirVar" placeholder="Психолог" autocomplete="off">'+
      '<datalist id="nDirVar"><option>Психолог</option><option>Сексолог</option><option>Семейный психолог</option><option>Детский психолог</option><option>Коуч</option></datalist></div></div>'+
      '<label for="nMail">Почта</label><input type="email" id="nMail" placeholder="specialist@example.com">'+
      '<div class="field-hint">Без почты система не сможет ни напомнить о завтрашних встречах, ни предупредить, что ссылки кончились.</div>'+
      '<input type="hidden" id="nPin" value="'+String(Math.floor(1000+Math.random()*9000))+'">'+
      '<button class="btn" data-a="addExpert">Добавить</button></div>';
  }
  return h;
}

/* Курсы школы: из чего ученица выбирает при записи и в профиле, и сколько
   встреч открыто на старте. Дальше личное число ученице ставит куратор. */
function aCourses(){
  var K=S.kursy;
  var h='<div class="card"><div class="eyebrow">Курсы</div><h1>Курсы и стартовые встречи</h1>'+
    '<p class="muted" style="margin-top:10px">Эти названия ученицы видят в выпадающих списках — при записи и в профиле. '+
    '«На старте» — сколько встреч открыто каждой, кто заходит на курс; обычно 1. '+
    'Личное число конкретной ученице ставится во вкладке «Ученицы» и оно главнее курса.</p></div>';
  if(!K) return h+'<div class="card"><div class="empty">Загружаем…</div></div>';

  h+='<div class="card"><h3>В списке: '+K.length+'</h3><div style="height:12px"></div>'+
    '<div class="scroll"><table><tr><th>Курс</th><th class="num">Учениц на курсе</th>'+
    '<th class="num">Встреч на старте</th><th></th></tr>';
  K.forEach(function(k,i){
    h+='<tr><td><b>'+esc(k.name)+'</b></td>'+
      '<td class="num">'+k.students+'</td>'+
      '<td class="num"><input type="number" min="0" max="99" style="width:70px;margin:0" '+
        'data-f="kSes" data-i="'+i+'" value="'+k.sessions+'"></td>'+
      '<td style="white-space:nowrap">'+
        (k.students>0
          ? '<span class="muted" title="Сначала переведите учениц на другой курс">—</span>'
          : '<button class="btn-link" data-a="kursDel" data-i="'+i+'" style="color:var(--alarm)">убрать</button>')+
      '</td></tr>';
  });
  h+='</table></div><div style="height:14px"></div>'+
    '<button class="btn btn-sm" data-a="kursSave">Сохранить</button>'+
    '<div class="field-hint" style="margin-top:10px">Число на старте действует на тех, кому куратор не вписал личное. '+
    'Поменяли — пересчитается у всех таких учениц курса сразу.</div></div>';

  h+='<div class="card"><h3>Добавить курс</h3><div style="height:12px"></div>'+
    '<div class="row"><div><label for="nkName">Название</label>'+
    '<input type="text" id="nkName" placeholder="Например: Наставник 2.0" autocomplete="off"></div>'+
    '<div><label for="nkSes">Встреч на старте</label>'+
    '<input type="number" id="nkSes" min="0" max="99" value="1"></div></div>'+
    '<button class="btn btn-sm" data-a="kursAdd">Добавить</button>'+
    '<div class="field-hint" style="margin-top:10px">Появится в списках у учениц сразу после добавления.</div></div>';
  return h;
}

function aTelegram(){
  var D=S.adm, s=D.settings, q=D.queue||{};
  var origin=location.origin+location.pathname.replace(/index\.html$/,'');

  var nastroyshchik = TECH || (S.me&&S.me.role==='owner') || D.role==='owner';
  if(!nastroyshchik){
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

  h+='<div class="card"><h3>Шаг 2. Поставить реле на компьютер</h3><div style="height:12px"></div>'+
    '<p class="muted">Один раз в Терминале на вашем Маке. Реле пропишется в автозапуск: '+
    'дальше оно включается вместе с компьютером само, терминал держать открытым не нужно.</p>'+
    '<div class="note mono">relay/install-relay-launchd.sh</div>'+
    '<button class="btn-ghost btn-sm" data-a="copy" data-txt="relay/install-relay-launchd.sh">Скопировать команду</button>'+
    '<div class="field-hint" style="margin-top:12px">Ключ реле скрипт спросит у сервера сам — вписывать ничего не нужно.<br>'+
    'Посмотреть, как дела: <span class="mono">relay/install-relay-launchd.sh --kak-dela</span><br>'+
    'Убрать из автозапуска: <span class="mono">relay/install-relay-launchd.sh --ubrat</span></div>'+
    '<div class="field-hint" style="margin-top:10px">Пока реле работает, сообщения в Telegram уходят за секунды. '+
    'Выключен компьютер — они ждут в очереди и уйдут, когда он включится. '+
    '<b>Письма на почту это не затрагивает</b>: их шлёт планировщик на сервере, реле для них не нужно.</div>'+
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
      '<div class="field-hint" style="margin-top:12px">Ссылки специалистов — во вкладке «Специалисты». Ученица подключается сама: кнопка появляется у него сразу после записи.</div>';
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
  var names={admin:'куратор',expert:'специалист',client:'ученица'};
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

function aSettings(){
  var s=S.adm.settings;
  var chk=function(id,key,label,hint){
    return '<label style="font-weight:400;display:flex;gap:9px;align-items:flex-start;margin-bottom:'+(hint?'4':'12')+'px">'+
      '<input type="checkbox" id="'+id+'" style="width:auto;margin:3px 0 0"'+(s[key]==='1'?' checked':'')+'>'+
      '<span style="font-size:14px">'+label+'</span></label>'+
      (hint?'<div class="field-hint" style="margin:0 0 14px 27px">'+hint+'</div>':'');
  };

  var h='<div class="card"><div class="eyebrow">Настройки</div><h1>Как всё устроено</h1>'+
    '<p class="muted" style="margin-top:10px">Здесь правила, по которым система работает сама. '+
    'Меняются редко — но если что-то идёт не так, чинить обычно нужно тут.</p></div>';

  /* ── запись ── */
  h+='<div class="card"><h3>Запись</h3><div style="height:14px"></div>'+
    chk('sMod','moderation','Запись подтверждает куратор',
        'Ученица оставляет заявку, время за ней держится, ссылка приходит после подтверждения. Защищает от записей по переславшейся ссылке.')+
    chk('sMailReq','email_req','Почта обязательна',
        'Телефон ученица может указать не тот, что в базе школы, — по почте сходится чаще.')+
    chk('sSticky','sticky','Повторная запись — к тому же специалисту',
        'Кто уже был, при следующей записи попадёт к своему, если тот открыл это время.')+
    chk('sChoose','choose_expert','Ученица сама выбирает специалиста',
        'Перед выбором времени она видит карточки специалистов — со своей сверху — и может вернуться к ней. '+
        'Выключите, и специалиста будет подбирать система, как раньше.')+
    '<label for="sCool">Не чаще одной встречи в, дней</label>'+
    '<input type="number" id="sCool" min="0" max="180" value="'+(s.cooldown||0)+'">'+
    '<div class="field-hint">'+((+s.cooldown)>0
      ? 'Сейчас ученица не может записаться, пока не пройдёт '+s.cooldown+' дней с прошлой встречи.'
      : '<b>Ноль — паузы нет:</b> записываются когда хотят, ограничивает только число открытых встреч.')+'</div>'+
    '<div class="field-hint">0 — без ограничения. Считается по обе стороны от выбранного времени.</div>'+
    '<label for="sDur">Длительность встречи, мин</label>'+
    '<input type="number" id="sDur" min="15" max="180" step="5" value="'+esc(s.duration)+'">'+
    '</div>';

  /* ── запас консультаций ── */
  h+='<div class="card"><h3>Сколько консультаций положено</h3><div style="height:14px"></div>'+
    chk('sLim','client_limit','Считать ученице оставшиеся консультации')+
    '<div class="field-hint">Курсы и стартовые числа встреч переехали в раздел «Курсы». '+
    'Личное число конкретной ученице — во вкладке «Ученицы».</div></div>';

  /* ── специалисты ── */
  h+='<div class="card"><h3>Специалисты</h3><div style="height:14px"></div>'+
    '<label for="sNorm">Норма встреч в месяц</label>'+
    '<input type="number" id="sNorm" min="0" max="500" value="'+(s.slot_norm||0)+'">'+
    '<div class="field-hint">0 — без нормы. В «Психологах» видно, сколько провели и сколько осталось довыставить.</div>'+
    '<label for="sRate">Ставка за проведённую консультацию, ₽</label>'+
    '<input type="number" id="sRate" min="0" step="100" value="'+(s.rate||0)+'">'+
    '<label for="sBy">Кто заводит специалистов</label>'+
    '<select id="sBy"><option value="owner"'+(s.experts_by!=='admin'?' selected':'')+'>только владелец</option>'+
    '<option value="admin"'+(s.experts_by==='admin'?' selected':'')+'>владелец и кураторы</option></select></div>';

  /* ── что уходит людям ── */
  h+='<div class="card"><h3>Что система пишет людям</h3><div style="height:14px"></div>'+
    chk('sR24','remind24','Напоминать ученице за сутки')+
    chk('sR2','remind2','Напоминать за два часа')+
    chk('sR15','remind15','Напоминать за пятнадцать минут')+
    chk('sAsk','ask_csat','Спрашивать отзыв после встречи')+
    chk('sNudge','nudge','Звать, если давно не записывалась',
        'Раз в неделю письмо «ждём вас на встрече». Только тем, у кого есть аккаунт: перенесённые из старых таблиц рассылку не заказывали. Молчим, если записаться всё равно нельзя.')+
    '<label for="sMail">Почта для тревог</label>'+
    '<input type="text" id="sMail" value="'+esc(s.alert_email||'')+'" placeholder="kurator@example.com">'+
    '<div class="field-hint">Сюда приходят заявки на доступ и предупреждение, если очередь сообщений встала.</div></div>';

  /* ── вход ── */
  h+='<div class="card"><h3>Вход и название</h3><div style="height:14px"></div>'+
    '<label for="sOrg">Название на шапке</label>'+
    '<input type="text" id="sOrg" value="'+esc(s.org)+'">'+
    '</div>';

  h+='<button class="btn" data-a="saveCfg">Сохранить настройки</button>';
  return h;
}

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
  /* Смена роли: селект — это change, кликом его не поймать */
  app.querySelectorAll('select[data-a="userRole"]').forEach(function(sel){
    sel.addEventListener('change',function(){
      var novaya=sel.value;
      if(!confirm('Сменить роль на «'+sel.options[sel.selectedIndex].text+'»? Права изменятся сразу.')){
        render(); return;
      }
      api('user_role',{id:sel.getAttribute('data-id'),role:novaya},'admin')
        .then(function(){ toast('Роль изменена.'); return loadPeople(); })
        .then(render).catch(function(e){ toast(e.message); render(); });
    });
  });
  var ci=document.getElementById('codeIn');
  if(ci) ci.addEventListener('keydown',function(e){ if(e.key==='Enter') act('openCode'); });
  /* Поля поиска: перерисовка съедает фокус и каретку, поэтому возвращаем
     их на место после каждого символа. */
  [['fExp','fExp'],['fStatus','fStatus'],['fFrom','fFrom'],['fTo','fTo']].forEach(function(pair){
    var f=document.getElementById(pair[0]);
    if(f) f.addEventListener('change',function(){ S[pair[1]]=f.value; render(); });
  });
  /* Календарь периода: как только тронули дату — период становится своим */
  [['pFrom','pFrom'],['pTo','pTo']].forEach(function(pair){
    var f=document.getElementById(pair[0]);
    if(f) f.addEventListener('change',function(){
      S[pair[1]]=f.value; S.period='own'; render(); loadRefusals();
    });
  });
  [['expSearch','expSearch'],['bookSearch','bookSearch'],['cliSearch','cliSearch'],
   ['eCliSearch','eCliSearch'],['peopleSearch','peopleSearch']].forEach(function(pair){
    var el=document.getElementById(pair[0]);
    if(!el) return;
    el.addEventListener('input',function(){
      S[pair[1]]=el.value;
      if(pair[1]==='bookSearch') S.bookFound=null;   /* новый запрос — старый архивный ответ не годится */
      var pos=el.selectionStart; render();
      var n=document.getElementById(pair[0]);
      if(n){ n.focus(); try{ n.setSelectionRange(pos,pos); }catch(e){} }
    });
  });
}

/* Кнопки, за которыми идёт запрос к серверу: их гасим на время ожидания
   и подсвечиваем зелёным, когда всё получилось. */
var ASYNC=['book','saveTopics','eSaveLink','addRange','addSlots','delSlot','saveReport',
  'addExpert','toggleExpert','saveCfg','saveTg','cliAdd','cliSave',
  'adminCancel','adminDelete','reissue','swapDo','moveDo','setLink','eSetLink','expMeSave','cliEditSave','kursSave','kursAdd','eaSave','denOtmetka',
  'tgList','tgMakeAdmin','tgUnbind','sendCsat','cancelCode','moveTo','openCode'];
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
  if(a==='home'){ S.view=startView(); S.topic=''; S.pair=null; S.pickedSlot=null; S.codeView=null; S.moveMode=false; boot().then(render); render(); return; }
  if(a==='client'){ S.view='client'; boot().then(render); render(); return; }
  if(a==='specPick'){ S.specFilter=el.getAttribute('data-v'); S.pickedSlot=null; S.bookErr=''; render(); return; }
  if(a==='pick'){ S.bookErr=''; S.pickedSlot=el.getAttribute('data-t'); render(); return; }
  if(a==='topic'){ S.topic=el.getAttribute('data-v'); S.pair=null; S.pickedSlot=null; render(); return; }
  if(a==='topicBack'){ S.topic=''; S.pair=null; S.pickedSlot=null; render(); return; }
  if(a==='pair'){ S.pair=el.getAttribute('data-v'); S.pickedSlot=null; render(); return; }
  if(a==='pairBack'){ S.pair=null; S.pickedSlot=null; render(); return; }

  /* --- запись --- */
  if(a==='bookMe'){
    S.busy=true; S.bookErr=''; render();
    api('book',{dt:S.pickedSlot,note:val('cNote'),category:S.topic,spec:S.specFilter||''},'client')
      .then(function(j){ S.busy=false; S.bookErr=''; S.lastBooking=j.booking; S.pickedSlot=null; S.view='done'; render(); })
      .catch(function(e){
        S.busy=false;
        /* Раньше при любой ошибке сбрасывали выбранное время и перерисовывали
           страницу — ученица видела, что «всё сбросилось», и не понимала почему.
           Теперь причина остаётся на экране, а время сбрасываем только если
           его действительно заняли. */
        S.bookErr=e.message;
        toast(e.message);
        var zanyali=/занял|уже прошло|выберите другое/i.test(e.message||'');
        if(zanyali){ boot().then(function(){ S.pickedSlot=null; S.bookErr=''; toast(e.message); render(); }); }
        else render();
      });
    return;
  }
  if(a==='book'){
    if(!document.getElementById('cAgree').checked) return toast('Отметьте согласие на обработку данных.');
    var first=val('cFirst'), last=val('cLast'), tg=val('cTg'), phone=val('cPhone');
    var name=(first+' '+last).trim();
    var contact=tg?('@'+tg.replace(/^@/,'')):phone;
    if(first.length<2) return toast('Напишите имя.');
    if(contact.length<4) return toast('Оставьте Telegram или телефон — по ним с вами свяжутся.');
    if(S.boot&&S.boot.emailReq&&!val('cMail')) return toast('Оставьте почту — по ней школа находит вас в своих записях.');
    S.busy=true; render();
    var courses=(S.boot&&S.boot.courses)||[];
    if(courses.length && !val('cCourse')) return toast('Выберите курс.');
    api('book',Object.assign({dt:S.pickedSlot,first:first,last:last,name:name,contact:contact,
                tg:tg,phone:phone,category:S.topic,course:val('cCourse'),
                note:val('cNote'),email:val('cMail'),pair:S.pair==='1'?'1':'0'},UTM))
      .then(function(j){
        S.busy=false; S.lastBooking=j.booking; S.pickedSlot=null; S.view='done'; render();
      })
      .catch(function(e){
        S.busy=false; toast(e.message);
        boot().then(function(){ S.pickedSlot=null; render(); });
      });
    return;
  }

  if(a==='wait'){
    var wn=val('wName'), wc=val('wContact');
    if(wn.length<2) return toast('Напишите, как к вам обращаться.');
    if(wc.length<4) return toast('Оставьте Telegram или телефон — иначе мы не сможем позвать.');
    S.busy=true; render();
    api('wait_add',{name:wn,contact:wc,email:val('wMail'),topic:S.topic,pair:S.pair==='1'?'1':'0'})
      .then(function(j){ S.busy=false; S.lastWait=j; S.view='waiting'; render(); })
      .catch(function(e){ S.busy=false; toast(e.message); render(); });
    return;
  }

  /* --- запись по коду --- */
  if(a==='authTab'){ S.authTab=el.getAttribute('data-v'); render(); return; }

  if(a==='go'){
    var v=el.getAttribute('data-v');
    if(v.indexOf('a:')===0){ S.adminTab=v.slice(2); v='admin'; }
    S.view=v; S.expHist=null; S.clientCard=null; window.scrollTo(0,0); render();
    if(S.adminTab==='cli' && v==='admin') api('clients',{},'admin').then(function(j){ S.clients=j; render(); }).catch(function(){});
    if(S.adminTab==='kursy' && v==='admin') api('courses_list',{},'admin').then(function(j){ S.kursy=j.courses; render(); }).catch(function(){});
    if(S.adminTab==='people' && v==='admin') loadPeople().then(render);
    if(v==='expert'&&!S.exp) loadExpert().then(render);
    if(v==='eClients'&&!S.exp) loadExpert().then(render);
    /* Профилю специалиста нужны его почта и телефон — они приходят вместе
       с остальным кабинетом. */
    if(v==='profile'&&S.me&&S.me.role==='expert'&&!S.exp) loadExpert().then(render);
    if(v==='admin'&&!S.adm) loadAdmin().then(render);
    if(v==='my') loadMy().then(render);
    if((v==='profile'||v==='book')&&S.me&&S.me.role==='client'&&!S.profile) loadProfile().then(render);
    return;
  }

  if(a==='doLogin'){
    var em=val('aEmail'), pw=val('aPass');
    if(!em||!pw) return toast('Впишите почту и пароль.');
    S.busy=true; render();
    api('auth_login',{email:em,pass:pw})
      .then(function(j){ S.busy=false; signIn(j); })
      .catch(function(e){ S.busy=false; render(); toast(e.message); });
    return;
  }
  if(a==='doReg'){
    var rn=val('rName'), re=val('rEmail'), rp=val('rPass'), rr=val('rRole');
    if(rn.length<2) return toast('Впишите имя и фамилию.');
    if(!/.+@.+\..+/.test(re)) return toast('Проверьте адрес почты.');
    if(rp.length<6) return toast('Пароль — минимум шесть символов.');
    S.busy=true; render();
    api('auth_register',{name:rn,email:re,pass:rp,role:rr,phone:val('rPhone'),tg:val('rTg')})
      .then(function(j){
        S.busy=false;
        if(j.pending){ S.pendingMsg=j.msg; S.view='pending'; render(); return; }
        signIn(j);
        /* Если школа узнала её по старой карточке — сказать вслух */
        if(j.privet) setTimeout(function(){ toast(j.privet); }, 400);
      })
      .catch(function(e){ S.busy=false; render(); toast(e.message); });
    return;
  }
  if(a==='profSave'){
    var cs=[].slice.call(app.querySelectorAll('.pCourse:checked')).map(function(x){return x.value;});
    api('client_profile_save',{name:val('pName'),phone:val('pPhone'),tg:val('pTg'),
                               about:val('pAbout'),courses:cs},'client')
      .then(function(){ return loadProfile(); })
      .then(function(){
        if(S.me) S.me.name=val('pName');
        /* Если она пришла сюда с полпути записи — вернём её обратно, к тому
           же времени, а не заставим искать слот заново. */
        if(S.pickedSlot && cs.length){
          toast('Курс отмечен. Возвращаю к записи.');
          S.view='book'; S.bookErr='';
        }else toast('Сохранили.');
        render();
      })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='fotoUp'){
    var f=(document.getElementById('pFoto')||{}).files;
    if(!f||!f[0]) return toast('Выберите файл.');
    apiFile('client_photo',f[0],'client')
      .then(function(){ toast('Фото загружено.'); return loadProfile(); }).then(render)
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='fotoDel'){
    api('client_photo_del',{},'client')
      .then(function(){ toast('Фото удалено.'); return loadProfile(); }).then(render)
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='myForgot'){
    api('auth_forgot',{email:(S.me&&S.me.email)||''})
      .then(function(){ toast('Письмо со ссылкой отправлено на '+((S.me&&S.me.email)||'вашу почту')+'.'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='doForgot'){
    var fe=val('aEmail');
    if(!/.+@.+\..+/.test(fe)) return toast('Проверьте адрес почты.');
    S.busy=true; render();
    api('auth_forgot',{email:fe})
      .then(function(){ S.busy=false; S.authTab='login'; render();
        toast('Если аккаунт есть — письмо со ссылкой уже летит.'); })
      .catch(function(e){ S.busy=false; render(); toast(e.message); });
    return;
  }
  if(a==='doReset'){
    var np=val('nPass');
    if(np.length<6) return toast('Пароль — минимум шесть символов.');
    S.busy=true; render();
    api('auth_reset',{t:S.resetToken,pass:np})
      .then(function(j){ S.busy=false;
        if(j.pending){ S.view='pending'; render(); return; }
        history.replaceState({},'',location.pathname); signIn(j); })
      .catch(function(e){ S.busy=false; render(); toast(e.message); });
    return;
  }
  if(a==='signOut'){
    setTok('client',''); setTok('expert',''); setTok('admin','');
    S.me=null; S.exp=null; S.adm=null; S.my=null; S.view='auth'; S.authTab='login'; render();
    return;
  }

  if(a==='myAsk'){
    var em=(document.getElementById('myMail')||{}).value||'';
    if(!/.+@.+\..+/.test(em.trim())) return toast('Проверьте адрес почты.');
    S.busy=true; render();
    api('my_request',{email:em.trim()})
      .then(function(){ S.busy=false; render();
        toast('Если на эту почту были записи — письмо со ссылкой уже летит.'); })
      .catch(function(e){ S.busy=false; render(); toast(e.message); });
    return;
  }
  if(a==='openCodeVal'){
    api('booking&code='+encodeURIComponent(el.getAttribute('data-v')))
      .then(function(j){ S.codeView=j.booking; S.codeErr=''; S.view='code'; window.scrollTo(0,0); render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }
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
    if(!confirm('Отменить запись? Слот освободится для другой ученицы.')) return;
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
    else { S.view='auth'; render(); }
    return;
  }
  if(a==='eLogout'){ setTok('expert',''); S.exp=null; S.pickedExpert=null; S.view='auth'; boot().then(render); render(); return; }
  if(a==='saveTopics'){
    var list=[];
    app.querySelectorAll('.topicBox').forEach(function(x){ if(x.checked) list.push(x.value); });
    if(!list.length) return toast('Отметьте хотя бы одну тему — иначе ученицы вас не найдут.');
    api('save_topics',{topics:list,pairs:document.getElementById('ePairs')&&document.getElementById('ePairs').checked?'1':'0'},'expert')
      .then(function(){ toast('Темы сохранены.'); return loadExpert(); }).then(function(){ doneBtn('saveTopics','Сохранено ✓'); })
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
  if(a==='tPreset'){
    S.tFrom=el.getAttribute('data-from'); S.tTo=el.getAttribute('data-to');
    var d=document.getElementById('dDate'); if(d&&d.value) S.draftDate=d.value;
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
  if(a==='repEdit'){ S.repEdit[el.getAttribute('data-id')]=1; render(); return; }

  if(a==='confYes'||a==='confNo'||a==='confClear'){
    var v=(a==='confYes')?'yes':(a==='confNo')?'no':'';
    api('confirm_client',{id:el.getAttribute('data-id'),value:v},'expert')
      .then(function(){
        toast(v==='yes'?'Отметила: ученица подтвердила.':v==='no'?'Отметила: не отвечает. Координатор увидит.':'Отметка снята.');
        return loadExpert();
      }).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='expCancel'){
    var why=prompt('Почему отменяете? Причину увидит ученица — так ей понятнее, что делать дальше.\n\nПосле отмены время освободится, а ученице придёт письмо с приглашением выбрать другое.','не смогу провести');
    if(why===null) return;
    api('expert_cancel',{id:el.getAttribute('data-id'),why:why.trim()},'expert')
      .then(function(){ toast('Встреча отменена, ученице отправлено письмо.'); return loadExpert(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='cliHist'){
    api('client_history',{id:el.getAttribute('data-id')},'expert')
      .then(function(j){ S.expHist=j; window.scrollTo(0,0); render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='histClose'){ S.expHist=null; render(); return; }

  if(a==='linksAdd'){
    var box=document.getElementById('eLinks');
    if(!box||!box.value.trim()) return toast('Вставьте ссылки, по одной в строке.');
    api('links_add',{links:box.value},'expert')
      .then(function(j){
        toast('Добавлено: '+j.added+(j.bad?', не разобрали: '+j.bad:'')+'. В запасе: '+j.free+'.');
        return loadExpert();
      }).then(render).catch(function(e){ toast(e.message); });
    return;
  }

  if(a==='saveReport'){
    var bid=el.getAttribute('data-id');
    var f=function(n){ var x=app.querySelector('[data-f="'+n+'"][data-id="'+bid+'"]'); return x?x.value:''; };
    api('report',{id:bid,status:f('status'),req:f('req'),plan:f('plan'),next:f('next'),paid:f('paid'),sum:f('sum')},'expert')
      .then(function(){ delete S.repEdit[bid]; toast(S.exp&&S.exp.staff?'Сохранено.':'Отчёт сдан.'); return loadExpert(); })
      .then(function(){ doneBtn('saveReport', S.exp&&S.exp.staff?'Сохранено ✓':'Отчёт сдан ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }

  /* --- координатор --- */
  if(a==='adminGo'){
    if(tok('admin')){ S.view='admin'; render(); loadAdmin().then(render); }
    else { S.view='auth'; render(); }
    return;
  }
  if(a==='fQuick'){
    var v=el.getAttribute('data-v'), d=new Date();
    var iso=function(x){ return x.toISOString().slice(0,10); };
    if(v==='clear'){ S.fExp=''; S.fStatus=''; S.fFrom=''; S.fTo=''; S.fKurs=''; S.bookSearch=''; S.bookFound=null; S.histFor=null; S.moveFor=null; }
    if(v==='today'){ S.fFrom=iso(d); S.fTo=iso(d); }
    if(v==='week'){ S.fFrom=iso(d); S.fTo=iso(new Date(d.getTime()+7*864e5)); }
    if(v==='month'){ S.fFrom=iso(new Date(d.getFullYear(),d.getMonth(),1));
                     S.fTo=iso(new Date(d.getFullYear(),d.getMonth()+1,0)); }
    render(); return;
  }
  if(a==='findAll'){
    api('bookings_find',{q:S.bookSearch},'admin')
      .then(function(j){ S.bookFound=j.rows; render();
        toast(j.total?('Нашлось в архиве: '+j.total):'В архиве тоже ничего.'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='uOk'){
    var uid=el.getAttribute('data-id');
    var sel=app.querySelector('[data-f="uexp"][data-id="'+uid+'"]');
    api('user_approve',{id:uid,expertId:sel?sel.value:''},'admin')
      .then(function(){ toast('Доступ открыт, человеку ушло письмо.'); return loadPeople(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='uNo'){
    if(!confirm('Отклонить заявку? Человек не сможет войти.')) return;
    api('user_reject',{id:el.getAttribute('data-id')},'admin')
      .then(function(){ toast('Заявка отклонена.'); return loadPeople(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='period'){
    S.period=el.getAttribute('data-v');
    if(S.period!=='own'){ var r=periodRange(); S.pFrom=r.d1; S.pTo=r.d2; }
    render(); loadRefusals(); return;
  }
  if(a==='aTab'){
    S.adminTab=el.getAttribute('data-v'); render();
    if(S.adminTab==='cli') api('clients',{},'admin').then(function(j){ S.clients=j; render(); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='dupesOpen'){
    S.dupesOpen=true; S.dupePairs=null; render(); window.scrollTo(0,0);
    api('dupes',{},'admin').then(function(j){ S.dupePairs=j.pairs; render(); })
      .catch(function(e){ toast(e.message); S.dupesOpen=false; render(); });
    return;
  }
  if(a==='dupesClose'){ S.dupesOpen=false; render(); return; }
  if(a==='dupeYes'){
    if(!confirm('Объединить карточки? История встреч и запас съедутся в одну — действие не отменить.')) return;
    api('client_merge',{id:el.getAttribute('data-main'),from:el.getAttribute('data-dup')},'admin')
      .then(function(){ toast('Объединили.'); return api('dupes',{},'admin'); })
      .then(function(j){ S.dupePairs=j.pairs; return api('clients',{},'admin'); })
      .then(function(j){ S.clients=j; render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='dupeNo'){
    api('dupe_not',{aId:el.getAttribute('data-a-id'),bId:el.getAttribute('data-b-id')},'admin')
      .then(function(){ toast('Запомнила: разные люди. Больше не спрошу.'); return api('dupes',{},'admin'); })
      .then(function(j){ S.dupePairs=j.pairs; return api('clients',{},'admin'); })
      .then(function(j){ S.clients=j; render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='kursSave' || a==='kursAdd' || a==='kursDel'){
    var K2=(S.kursy||[]).map(function(k,i){
      var e=app.querySelector('[data-f="kSes"][data-i="'+i+'"]');
      return {name:k.name, sessions:e?e.value:k.sessions, students:k.students};
    });
    if(a==='kursDel'){
      var ii=parseInt(el.getAttribute('data-i'),10);
      if(!confirm('Убрать курс «'+K2[ii].name+'» из списка?')) return;
      K2.splice(ii,1);
    }
    if(a==='kursAdd'){
      var nk=val('nkName').trim();
      if(nk.length<2) return toast('Впишите название курса.');
      if(K2.some(function(k){return k.name.toLowerCase()===nk.toLowerCase();})) return toast('Такой курс уже есть.');
      K2.push({name:nk, sessions:val('nkSes')||'1', students:0});
    }
    api('courses_save',{courses:K2},'admin')
      .then(function(){ toast(a==='kursAdd'?'Курс добавлен.':'Сохранено.'); return api('courses_list',{},'admin'); })
      .then(function(j){ S.kursy=j.courses; render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='cliFilter'){ S.cliFilter=el.getAttribute('data-v'); S.cliAll=false; render(); return; }
  if(a==='cliAll'){ S.cliAll=true; render(); return; }
  if(a==='cliCard'){
    api('client_card',{id:el.getAttribute('data-id')},'admin')
      .then(function(j){ S.clientCard=j; render(); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='cliClose'){ S.clientCard=null; render(); return; }
  if(a==='cliMerge'){
    var mid=el.getAttribute('data-id');
    if(!confirm('Склеить карточки? Встречи и запас соберутся в одной, лишняя исчезнет.')) return;
    api('client_merge',{id:mid,from:el.getAttribute('data-from')},'admin')
      .then(function(){ toast('Карточки склеены.'); return api('clients',{},'admin'); })
      .then(function(j){ S.clients=j; return api('client_card',{id:mid},'admin'); })
      .then(function(j){ S.clientCard=j; render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='cliNoteSave'){
    var nid=el.getAttribute('data-id');
    var nn=document.getElementById('cliNote');
    api('client_save',{id:nid,note:nn?nn.value:''},'admin')
      .then(function(){ toast('Заметка сохранена.'); return api('client_card',{id:nid},'admin'); })
      .then(function(j){ S.clientCard=j; render(); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='cliBlock'){
    var bid3=el.getAttribute('data-id'), on=el.getAttribute('data-on');
    var why='';
    if(on==='1'){ why=prompt('Почему закрываем запись? Причину увидит только куратор.','ушла на возврат'); if(why===null) return; }
    api('client_block',{id:bid3,on:on,why:why},'admin')
      .then(function(){ toast(on==='1'?'Запись закрыта.':'Запись снова разрешена.'); return api('client_card',{id:bid3},'admin'); })
      .then(function(j){ S.clientCard=j; render(); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='cliSave'){
    var cid=el.getAttribute('data-id');
    var pole=function(f){ var e=app.querySelector('[data-f="'+f+'"][data-id="'+cid+'"]'); return e?e.value:null; };
    var dan={id:cid};
    var kol=pole('allowed'); if(kol!==null) dan.allowed=kol;
    var kur=pole('course');  if(kur!==null) dan.course=kur;
    api('client_save',dan,'admin')
      .then(function(){ toast('Сохранено.'); return api('clients',{},'admin'); })
      .then(function(j){ S.clients=j; render(); doneBtn('cliSave','Сохранено ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='cliNew'){ S.cliNew=!S.cliNew; render(); return; }
  if(a==='cliAdd'){
    api('client_add',{name:val('nuName'),phone:val('nuPhone'),tg:val('nuTg'),
                      email:val('nuMail'),course:val('nuCourse'),allowed:val('nuAllowed')},'admin')
      .then(function(){ toast('Ученица заведена.'); S.cliNew=false; S.cliSearch=''; return api('clients',{},'admin'); })
      .then(function(j){ S.clients=j; render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='cliEdit'){
    var eid3=el.getAttribute('data-id');
    S.cliEdit = String(S.cliEdit)===String(eid3) ? null : eid3;
    render(); return;
  }
  if(a==='cliEditSave'){
    var cid3=el.getAttribute('data-id');
    var pole3=function(f){ var e=app.querySelector('[data-f="'+f+'"][data-id="'+cid3+'"]'); return e?e.value:''; };
    api('client_save',{id:cid3,name:pole3('eName'),phone:pole3('ePhone'),tg:pole3('eTg'),
                       email:pole3('eMail'),note:pole3('eNote'),
                       course:pole3('eCourse'),allowed:pole3('eAllowed')},'admin')
      .then(function(){ toast('Сохранено. Если у неё есть аккаунт с этой почтой — увидит у себя.'); S.cliEdit=null; return api('clients',{},'admin'); })
      .then(function(j){ S.clients=j; render(); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='cliDel'){
    var did=el.getAttribute('data-id');
    /* Сначала узнаём у сервера, что именно исчезнет: решать вслепую нельзя */
    api('client_del_info',{id:did},'admin').then(function(i){
      var txt;
      if(!i.vsego){
        txt='Удалить карточку «'+i.name+'»?\n\nВстреч у неё нет — терять нечего.';
      }else{
        txt='Удалить «'+i.name+'» вместе с историей?\n\n'+
            'Исчезнут '+i.vsego+' встреч(и)'+
            (i.vperedi?', из них '+i.vperedi+' ещё впереди — эти часы вернутся специалистам':'')+
            (i.akkaunt?'\nИ её аккаунт '+i.akkaunt+' — войти она больше не сможет':'')+
            '\n\nЭто не отменить. Если она просто больше не учится, лучше закрыть ей запись '+
            '(кнопка «история» → «Закрыть запись») — тогда история останется.';
      }
      if(!confirm(txt)) return;
      return api('client_del',{id:did,confirm:'1'},'admin')
        .then(function(r){ toast(r.udaleno?('Удалена вместе с '+r.udaleno+' встречами.'):'Карточка удалена.');
          return api('clients',{},'admin'); })
        .then(function(j){ S.clients=j; render(); });
    }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='denOtmetka'){
    var did=el.getAttribute('data-id'), isxod=el.getAttribute('data-v');
    api('report',{id:did,status:isxod},'expert')
      .then(function(){ toast(isxod==='held'?'Отметила: провели.':'Отметила: не пришла.'); return loadExpert(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='eaSave'){
    var ck9=el.getAttribute('data-ck');
    var p9=app.querySelector('[data-f="eaAllowed"][data-ck="'+ck9+'"]');
    api('expert_set_allowed',{ckey:ck9,allowed:p9?p9.value:'0'},'expert')
      .then(function(){ toast('Сохранено.'); return loadExpert(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='eKurs'){ S.eKurs=el.getAttribute('data-v'); S.eCliAll=false; render(); return; }
  if(a==='eCliAll'){ S.eCliAll=true; render(); return; }
  if(a==='cliKurs'){ S.cliKurs=el.getAttribute('data-v'); S.cliAll=false; render(); return; }
  if(a==='aLogout'){
    setTok('admin','');
    S.adm=null; S.adminTab='dash'; S.tgList=null; S.smsInfo=null; S.zoomWho=null;
    S.swapFor=null; S.lastReissue=null; S.lastImport=null;
    S.view='auth'; render();
    return;
  }
  if(a==='bookOk'){
    var bid=el.getAttribute('data-id');
    var nb=app.querySelector('[data-f="admnote"][data-id="'+bid+'"]');
    var lk=app.querySelector('[data-f="admlink"][data-id="'+bid+'"]');
    api('booking_ok',{id:bid,note:nb?nb.value:'',link:lk?lk.value.trim():''},'admin')
      .then(function(){ toast('Запись подтверждена, ссылка ушла ученице.'); return loadAdmin(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='bookNo'){
    var bid2=el.getAttribute('data-id');
    var why=prompt('Почему отклоняем? Причину увидит ученица. Можно оставить пустым.');
    if(why===null) return;
    api('booking_no',{id:bid2,why:why},'admin')
      .then(function(){ toast('Заявка отклонена, время снова свободно.'); return loadAdmin(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='waitDel'){
    api('wait_del',{id:el.getAttribute('data-v')},'admin')
      .then(function(){ return loadAdmin(); }).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='adminCancel'){
    if(!confirm('Отменить эту запись? Слот вернётся в общий пул, обеим сторонам уйдёт уведомление.')) return;
    api('admin_cancel',{id:el.getAttribute('data-id')},'admin')
      .then(function(){ toast('Отменено.'); return loadAdmin(); }).then(render)
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='closeReissue'){ S.lastReissue=null; render(); return; }
  if(a==='swapStart'){ S.swapFor=el.getAttribute('data-id'); S.adminTab='book'; render(); return; }
  if(a==='swapCancel'){ S.swapFor=null; render(); return; }
  if(a==='unassign'){
    var why=prompt('Снять специалиста с этой записи?\n\nУченице НИЧЕГО не уйдёт, время за ней останется. '+
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
        toast(j.via ? ('Назначено: '+j.expert+'. Ученице ушло: '+({tg:'Telegram',sms:'SMS',mail:'почта'}[j.via]||j.via))
                    : ('Назначено: '+j.expert+'. Ученице отправить нечем — сообщите вручную.'));
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
  if(a==='histFor'){
    var id=el.getAttribute('data-id');
    if(String(S.histFor)===String(id)){ S.histFor=null; S.histLog=null; render(); return; }
    S.histFor=id; S.histLog=null; render();
    api('booking_log&id='+encodeURIComponent(id),null,'admin')
      .then(function(j){ S.histLog=j.events; render(); }).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='eSetLink'){
    var eid2=el.getAttribute('data-id');
    var p2=document.getElementById('el'+eid2);
    if(!p2 || !p2.value.trim()) return toast('Вставьте ссылку на встречу.');
    api('booking_link',{id:eid2,link:p2.value.trim()},'expert')
      .then(function(){ toast('Ссылка сохранена, ученице отправлено.'); return loadExpert(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='expMeSave'){
    api('expert_me_save',{email:val('myMail'),phone:val('myTel')},'expert')
      .then(function(){ toast('Сохранили. Теперь уведомления дойдут.'); return loadExpert(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='peopleRole'){ S.peopleRole=el.getAttribute('data-v'); render(); return; }
  if(a==='userAccess'){
    var open=el.getAttribute('data-v')==='1';
    if(!confirm(open?'Вернуть доступ?':'Закрыть доступ? Человек перестанет входить прямо сейчас.')) return;
    api('user_access',{id:el.getAttribute('data-id'),open:open?'1':'0'},'admin')
      .then(function(){ toast(open?'Доступ вернули.':'Доступ закрыт.'); return loadPeople(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='setLink'){
    var lid=el.getAttribute('data-id');
    var pole=document.getElementById('lk'+lid);
    if(!pole || !pole.value.trim()) return toast('Вставьте ссылку на встречу.');
    api('booking_link',{id:lid,link:pole.value.trim()},'admin')
      .then(function(){ toast('Ссылка сохранена, ученице отправлено.'); return loadAdmin(); })
      .then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='admMove'){
    var mid=el.getAttribute('data-id');
    S.moveFor = String(S.moveFor)===String(mid) ? null : mid;
    render(); return;
  }
  if(a==='moveDo'){
    var bid=el.getAttribute('data-id');
    var sel=document.getElementById('mv'+bid);
    if(!sel||!sel.value) return toast('Выберите новое время.');
    var kogda=sel.value;
    if(!confirm('Перенести встречу на '+fmtFull(kogda)+'?')) return;
    api('admin_move',{id:bid,dt:kogda},'admin')
      .then(function(){ toast('Перенесли на '+fmtFull(kogda)+'.'); S.moveFor=null; S.histLog=null; return loadAdmin(); })
      .then(function(){
        render();
        /* Перенос заводит новую запись — старая карточка уже не та */
        S.histFor=null; render();
      })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='fKurs'){ S.fKurs=el.getAttribute('data-v'); S.histFor=null; S.moveFor=null; render(); return; }
  if(a==='reissue'){
    if(!confirm('Выпустить новую ссылку на встречу? Старая сразу перестанет работать.')) return;
    api('reissue_link',{id:el.getAttribute('data-id')},'admin')
      .then(function(j){
        S.lastReissue=j;
        toast(j.client==='нет канала'
          ? 'Ссылка обновлена. Ученице отправить нечем — скопируйте текст ниже.'
          : 'Ссылка обновлена и отправлена ученице.');
        return loadAdmin();
      }).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='adminDelete'){
    if(!confirm('Удалить запись навсегда? Это для тестовых и ошибочных — уведомления никому не уйдут, отменить удаление нельзя.')) return;
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
  if(a==='addExpert'){
    api('expert_add',{name:val('nName'),pin:val('nPin'),link:val('nLink'),email:val('nMail'),dir:val('nDir')},'admin')
      .then(function(){ toast('Добавлена в пул.'); return loadAdmin(); }).then(function(){ doneBtn('addExpert','Добавлена ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='expLinks'){
    var who=el.getAttribute('data-v');
    var txt=prompt('Ссылки на встречи для «'+who+'» — по одной в строке.\n\nНарежьте их в SaluteJazz или Телемосте: система выдаст по одной на каждую подтверждённую заявку и вернёт обратно, если встречу отменят.','');
    if(txt===null||!txt.trim()) return;
    api('links_add',{expertId:el.getAttribute('data-id'),links:txt},'admin')
      .then(function(j){ toast('Добавлено: '+j.added+(j.bad?', не разобрали: '+j.bad:'')+'. В запасе: '+j.free+'.');
        return loadAdmin(); }).then(render).catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='expDir'){
    var td=prompt('Кто это? Например: Психолог, Сексолог, Семейный психолог.', el.getAttribute('data-v')||'Психолог');
    if(td===null) return;
    api('expert_dir',{id:el.getAttribute('data-id'),dir:td},'admin')
      .then(function(){ toast('Записано.'); return loadAdmin(); }).then(render)
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='expMail'){
    var em=prompt('Почта специалиста — на неё уйдут напоминания и предупреждения:', el.getAttribute('data-v')||'');
    if(em===null) return;
    api('expert_email',{id:el.getAttribute('data-id'),email:em.trim()},'admin')
      .then(function(){ toast('Сохранено.'); return loadAdmin(); }).then(render).catch(function(e){ toast(e.message); });
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
    /* Шлём только те поля, что есть на экране. «Нет чекбокса» не должно
       значить «выключить» — иначе спрятанная настройка тихо сбрасывается. */
    var cfg={};
    var put=function(key,id){ var e=document.getElementById(id); if(e) cfg[key]=e.value; };
    var flag=function(key,id){ var e=document.getElementById(id); if(e) cfg[key]=e.checked?'1':'0'; };

    put('duration','sDur'); put('org','sOrg');

    put('rate','sRate'); put('slot_norm','sNorm'); put('cooldown','sCool');
    put('experts_by','sBy'); put('alert_email','sMail');
    flag('sticky','sSticky'); flag('choose_expert','sChoose'); flag('client_limit','sLim'); flag('email_req','sMailReq');
    flag('moderation','sMod'); flag('nudge','sNudge');
    flag('remind24','sR24'); flag('remind2','sR2'); flag('remind15','sR15'); flag('ask_csat','sAsk');

    api('settings_save',cfg,'admin')
      .then(function(){ toast('Настройки сохранены.'); return loadAdmin(); })
      .then(function(){ doneBtn('saveCfg','Сохранено ✓'); })
      .catch(function(e){ toast(e.message); });
    return;
  }
  if(a==='csv'){
    window.open(API+'?action=csv&token='+encodeURIComponent(tok('admin')),'_blank');
    return;
  }

  if(a==='csvCli'||a==='csvPay'||a==='csvWait'){
    var act={csvCli:'csv_clients',csvPay:'csv_pay',csvWait:'csv_wait'}[a];
    var url=API+'?action='+act+'&token='+encodeURIComponent(tok('admin'));
    if(a==='csvPay'){
      var m2=prompt('За какой месяц? Формат ГГГГ-ММ:', new Date().toISOString().slice(0,7));
      if(m2===null) return;
      url+='&month='+encodeURIComponent(m2.trim());
    }
    window.open(url,'_blank');
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
    if(any) localStorage.setItem('shk_utm',JSON.stringify(got));
    else got=JSON.parse(localStorage.getItem('shk_utm')||'{}');
  }catch(e){}
  return got||{};
})();
/* Если токен уже лежит — спрашиваем сервер, кто это, и открываем его
   кабинет. Токен мог протухнуть или роль могли закрыть. */
function restoreSession(){
  var role=tok('admin')?'admin':(tok('expert')?'expert':(tok('client')?'client':''));
  if(!role) return Promise.resolve();
  return api('auth_me',null,role)
    .then(function(j){ S.me=j.me; })
    .catch(function(e){
      /* Токен и правда не годится — забываем его, но только его. */
      if(/вход|доступ/i.test(e.message||'')) setTok(role,'');
    });
}

boot().then(restoreSession).then(function(){
  S.view=startView();
  var tab=qs.get('tab');
  if(ROLE==='admin' && ['dash','book','exp','tg','msg','set'].indexOf(tab)>=0) S.adminTab=tab;
  /* Ссылка из письма «все ваши встречи» */
  var me=qs.get('me');
  if(me && ROLE==='client'){
    return api('my_view&t='+encodeURIComponent(me))
      .then(function(j){ S.my=j; S.view='my'; })
      .catch(function(e){ S.codeErr=e.message; S.codeView=null; S.view='code'; });
  }
  /* Ссылка на смену пароля */
  var rt=qs.get('reset');
  if(rt){ S.resetToken=rt; S.view='reset'; return; }

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
  if(S.view==='my' && !S.my) loadMy().then(render);
  startPoll();
});
})();
