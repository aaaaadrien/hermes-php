'use strict';

/* ============================================================
   Hermes (PHP) — Logique de la SPA (vanilla JS)
   Reprend le comportement de l'application Streamlit Python :
   connexion, conversations, amphores, raisonnement, chat SSE,
   fichiers joints, TTS et administration.
   ============================================================ */

// ============================ État global ============================
const CONFIG = {
  seuil_upload_chunk: 5 * 1024 * 1024,  // 5 Mo : seuil au-delà duquel on utilise le chunking
  taille_chunk: 1 * 1024 * 1024,         // 1 Mo par chunk
};
let USER = null;            // {id, username, is_admin, ...} ou null (mode none)
let CONVERSATIONS = [];     // liste des conversations (mode userpass)
let AMPHORES = [];          // liste des amphores accessibles
let conversationId = null;  // id de la conversation courante (null = éphémère)
let modeEphemere = false;   // true = conversation non sauvegardée
let amphoreId = '';         // id de l'amphore sélectionnée
let reasoningNiveau = '';   // niveau de raisonnement à envoyer
let reasoningNiveaux = [];  // liste des niveaux disponibles
let reasoningIndex = 0;     // index courant dans la liste
let fichierJoint = null;    // {file:File, nom, mime}
let enCours = false;        // une réponse est en cours de streaming
let controleurArret = null; // AbortController de la requête en cours (bouton stop)
let idDemandeCourante = ''; // identifiant aléatoire envoyé à /api/stop
const MARQUEUR_ARRET = '> ⏹ *Génération arrêtée.*'; // identique au serveur (persistance)
let modalAmphoreId = null;     // amphore en cours d'édition (null = création)
let modalAmphoreGlobal = false; // création d'une amphore globale (admin)
let theme = 'light';            // thème actuel

// ============================ Utilitaires ============================
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s == null ? '' : s)
  .replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

async function api(path, options = {}) {
  const opts = { credentials: 'include', ...options };
  if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
    opts.headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
    opts.body = JSON.stringify(opts.body);
  }
  const res = await fetch(path, opts);
  const ct = res.headers.get('content-type') || '';
  if (ct.includes('application/json')) {
    const data = await res.json();
    if (!res.ok) throw new Error(data.erreur || ('Erreur HTTP ' + res.status));
    return data;
  }
  if (!res.ok) throw new Error('Erreur HTTP ' + res.status);
  return res;
}

/**
 * Upload d'un fichier par chunks de 1 Mo.
 * Retourne l'upload_id à utiliser dans la requête de chat.
 */
async function uploaderChunks(fichier, onProgress, signal) {
  const uploadId = 'h_' + Math.random().toString(36).substring(2, 18) + Date.now().toString(36);
  const totalChunks = Math.max(1, Math.ceil(fichier.size / CONFIG.taille_chunk));
  let chunksEnvoyes = 0;

  try {
    for (let index = 0; index < totalChunks; index++) {
      const debut = index * CONFIG.taille_chunk;
      const fin = Math.min(debut + CONFIG.taille_chunk, fichier.size);
      const blobChunk = fichier.slice(debut, fin);

      const fd = new FormData();
      fd.append('upload_id', uploadId);
      fd.append('index', String(index));
      fd.append('nom', fichier.name);
      fd.append('mime', fichier.type || 'application/octet-stream');
      fd.append('taille', String(fichier.size));
      fd.append('chunk', blobChunk, 'chunk');

      await api('/api/upload/chunk', { method: 'POST', body: fd, signal });
      chunksEnvoyes++;

      if (onProgress) {
        onProgress(chunksEnvoyes, totalChunks);
      }
    }
  } catch (e) {
    // Interruption (bouton stop / onglet fermé) ou erreur après l'envoi de
    // quelques chunks : la requête /api/chat ne partira pas, donc les chunks
    // stockés sur le serveur seraient orphelins. On les supprime (sans
    // signaler l'erreur : le nettoyage est du meilleur effort).
    if (chunksEnvoyes > 0) {
      api('/api/upload/clean', { method: 'POST', body: { upload_id: uploadId } }).catch(() => {});
    }
    throw e;
  }

  return uploadId;
}

let toastTimer = null;
function toast(msg, ms = 3500) {
  const t = $('toast');
  t.textContent = msg;
  t.classList.remove('hidden');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.add('hidden'), ms);
}

// ============================ Thème ============================
function appliquerTheme(t) {
  theme = t;
  document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : '');
  const optClair = $('opt-light');
  const optSombre = $('opt-dark');
  if (optClair) optClair.classList.toggle('selected', t === 'light');
  if (optSombre) optSombre.classList.toggle('selected', t === 'dark');
  const radio = document.querySelector(`input[name="theme"][value="${t}"]`);
  if (radio) radio.checked = true;
}

async function changerTheme(t) {
  if (!USER) { appliquerTheme(t); return; }
  try {
    await api('/api/profile', { method: 'PUT', body: { theme: t } });
    appliquerTheme(t);
  } catch (e) { toast('Erreur lors de la sauvegarde du thème'); }
}

// ============================ Markdown (sécurisé) ============================
function mdInline(s) {
  s = s.replace(/`([^`]+)`/g, '<code class="code-inline">$1</code>');
  s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
  s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]+)\)/g,
    (m, label, url) =>
      url.startsWith('/')
        ? '<a href="' + url + '" download>' + label + '</a>'
        : '<a href="' + url + '" target="_blank" rel="noopener">' + label + '</a>');
  return s;
}

function renderMarkdown(text) {
  if (text == null) return '';
  const lignes = esc(String(text)).split('\n');
  const out = [];
  let enCode = false, codeBuf = [], enListe = false, enPara = false, enTable = false;
  let tableBuf = [];

  const fermerPara = () => { if (enPara) { out.push('</p>'); enPara = false; } };
  const fermerListe = () => { if (enListe) { out.push('</ul>'); enListe = false; } };
  const fermerTable = () => {
    if (enTable && tableBuf.length >= 3) {
      // Premier ligne = en-tête, deuxième = séparateur, restantes = données
      const enTete = tableBuf[0].split('|').filter(c => c.trim() !== '').map(c => '<th>' + c.trim() + '</th>').join('');
      const lignesData = tableBuf.slice(2).map(ligne => {
        const cellules = ligne.split('|').filter(c => c.trim() !== '').map(c => '<td>' + c.trim() + '</td>').join('');
        return '<tr>' + cellules + '</tr>';
      });
      out.push('<table><thead><tr>' + enTete + '</tr></thead><tbody>' + lignesData.join('') + '</tbody></table>');
    }
    enTable = false;
    tableBuf = [];
  };

  for (const brut of lignes) {
    if (brut.trim().startsWith('```')) {
      fermerPara(); fermerListe(); fermerTable();
      if (!enCode) { enCode = true; codeBuf = []; }
      else { out.push('<pre class="code-block"><code>' + codeBuf.join('\n') + '</code></pre>'); enCode = false; }
      continue;
    }
    if (enCode) { codeBuf.push(brut); continue; }

    // Détection de tableau : ligne commençant par |
    if (brut.trim().startsWith('|') && brut.trim().endsWith('|')) {
      fermerPara(); fermerListe();
      enTable = true;
      tableBuf.push(brut.trim());
      continue;
    }
    // Si on était dans un tableau et que la ligne ne commence pas par |, on ferme le tableau
    if (enTable) {
      fermerTable();
    }

    const ligne = brut;
    let m;
    if ((m = ligne.match(/^(#{1,6})\s+(.*)$/))) {
      fermerPara(); fermerListe();
      const n = m[1].length;
      out.push('<h' + n + '>' + mdInline(m[2]) + '</h' + n + '>');
    } else if ((m = ligne.match(/^\s*[-*]\s+(.*)$/))) {
      fermerPara();
      if (!enListe) { out.push('<ul>'); enListe = true; }
      out.push('<li>' + mdInline(m[1]) + '</li>');
    } else if (ligne.trim() === '') {
      fermerPara(); fermerListe();
    } else {
      fermerListe();
      if (!enPara) { out.push('<p>'); enPara = true; } else { out.push('<br>'); }
      out.push(mdInline(ligne));
    }
  }
  if (enCode) out.push('<pre class="code-block"><code>' + codeBuf.join('\n') + '</code></pre>');
  fermerTable(); fermerPara(); fermerListe();
  return out.join('');
}

// ============================ Initialisation ============================
async function init() {
  let erreurUrl = null;
  try {
    erreurUrl = new URLSearchParams(location.search).get('erreur');
  } catch (e) { /* ignore */ }

  try {
    Object.assign(CONFIG, await api('/api/config'));
  } catch (e) {
    document.body.innerHTML = '<div style="padding:40px;font-family:sans-serif">'
      + 'Impossible de charger la configuration : ' + esc(e.message) + '</div>';
    return;
  }
  appliquerConfig();

  let authentifie = false;
  try {
    const me = await api('/api/me');
    USER = me.user;
    authentifie = me.authentifie;
  } catch (e) { /* non authentifié */ }

  if (authentifie) {
    montrerApp();
  } else if (CONFIG.auth_mode === 'userpass') {
    montrerLogin(erreurUrl);
  } else {
    // Mode éphémère (none) : pas d'écran de connexion.
    montrerApp();
  }
}

function appliquerConfig() {
  $('login-title').textContent = CONFIG.page_title || 'Hermes';
  const titre = CONFIG.header || CONFIG.page_title || 'Hermes';
  const icone = CONFIG.page_icon || '📜';
  $('header-title').textContent = titre;
  $('header-logo').textContent = icone;
  document.title = CONFIG.page_title || 'Hermes';

  // Onglet "créer un compte"
  if (CONFIG.register_enabled) {
    $('login-tabs').querySelector('[data-tab="register"]').classList.remove('hidden');
  }
  // Bouton SSO
  if (CONFIG.oidc_enabled) {
    $('login-divider').classList.remove('hidden');
    $('btn-login-oidc').classList.remove('hidden');
  }

  // Sélecteur de raisonnement
  const rm = CONFIG.reasoning_mode || '';
  const btnRais = $('btn-reasoning');
  const menuRais = $('reasoning-menu');
  if (rm === 'user') {
    reasoningNiveaux = CONFIG.reasoning_levels || ['none', 'minimal', 'low', 'medium', 'high', 'xhigh'];
    reasoningIndex = Math.max(0, reasoningNiveaux.indexOf('medium'));
    reasoningNiveau = reasoningNiveaux[reasoningIndex];
    btnRais.classList.remove('hidden');
    for (const niv of reasoningNiveaux) {
      const div = document.createElement('div');
      div.className = 'menu-item';
      div.dataset.level = niv;
      const labels = { none: 'Aucun', minimal: 'Minimal', low: 'Faible', medium: 'Moyen', high: 'Élevé', xhigh: 'Très élevé', user: 'Utilisateur', '': 'Par défaut' };
      div.textContent = labels[niv] || niv;
      menuRais.appendChild(div);
    }
    majMenuRais();
    const labels = { none: 'Aucun', minimal: 'Minimal', low: 'Faible', medium: 'Moyen', high: 'Élevé', xhigh: 'Très élevé', user: 'Utilisateur', '': 'Par défaut' };
    $('reasoning-label').textContent = labels[reasoningNiveau] || reasoningNiveau;
  } else if (rm) {
    reasoningNiveau = rm; // niveau imposé
    btnRais.classList.add('hidden');
  } else {
    reasoningNiveau = '';
    btnRais.classList.add('hidden');
  }

  // Thème
  if (CONFIG.theme) appliquerTheme(CONFIG.theme);

  // Dictée vocale : masquée si désactivée côté serveur (whisper.dictation)
  // ou si le navigateur ne propose pas MediaRecorder / getUserMedia
  // (contexte non sécurisé).
  const dicteeOk = CONFIG.dictation_enabled
    && typeof MediaRecorder !== 'undefined'
    && navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function';
  $('btn-dictation').classList.toggle('hidden', !dicteeOk);
}

function majMenuRais() {
  const menuRais = $('reasoning-menu');
  const items = menuRais.querySelectorAll('.menu-item');
  items.forEach((item) => {
    item.classList.toggle('selected', item.dataset.level === reasoningNiveau);
  });
}

// ============================ Écrans ============================
function montrerLogin(erreur) {
  $('login-screen').classList.remove('hidden');
  $('app').classList.add('hidden');
  const sous = (CONFIG.auth_mode === 'userpass' && CONFIG.register_enabled)
    ? 'Connectez-vous ou créez un compte pour continuer.'
    : 'Connectez-vous pour continuer.';
  $('login-subtitle').textContent = sous;
  if (erreur) {
    $('login-error').textContent = erreur;
    $('login-error').classList.remove('hidden');
  }
}

function montrerApp() {
  $('login-screen').classList.add('hidden');
  $('app').classList.remove('hidden');
  rendreUser();
  if (CONFIG.amphores_mode === 'user' || CONFIG.amphores_mode === 'all') {
    chargerAmphores();
  }
  if (USER) {
    chargerConversations();
  }
}

function rendreUser() {
  const menu = $('user-menu');
  menu.innerHTML = '';
  if (USER) {
    $('user-name').textContent = USER.username;
    $('user-avatar').textContent = (USER.username || '?').charAt(0).toUpperCase();

    const itemProfil = document.createElement('div');
    itemProfil.className = 'menu-item';
    itemProfil.textContent = 'Profil';
    itemProfil.addEventListener('click', () => { menu.classList.add('hidden'); ouvrirModale('profil'); });
    menu.appendChild(itemProfil);

    if (USER.is_admin) {
      const itemConfig = document.createElement('div');
      itemConfig.className = 'menu-item';
      itemConfig.textContent = 'Config système';
      itemConfig.addEventListener('click', () => { menu.classList.add('hidden'); ouvrirModaleConfig(); });
      menu.appendChild(itemConfig);

      const itemAdmin = document.createElement('div');
      itemAdmin.className = 'menu-item';
      itemAdmin.textContent = 'Administration';
      itemAdmin.addEventListener('click', () => { menu.classList.add('hidden'); ouvrirAdmin(); });
      menu.appendChild(itemAdmin);
    }

    const itemDeconnexion = document.createElement('div');
    itemDeconnexion.className = 'menu-item';
    itemDeconnexion.textContent = 'Déconnexion';
    itemDeconnexion.addEventListener('click', () => { menu.classList.add('hidden'); faireLogout(); });
    menu.appendChild(itemDeconnexion);
  } else {
    $('user-name').textContent = 'Anonyme';
    $('user-avatar').textContent = '?';
  }
}

// ============================ Login / Register / Logout ============================
async function faireLogin(ev) {
  ev.preventDefault();
  const btn = $('btn-login');
  btn.disabled = true;
  $('login-error').classList.add('hidden');
  try {
    const r = await api('/api/login', {
      method: 'POST',
      body: { username: $('login-username').value, password: $('login-password').value },
    });
    USER = r.user;
    if (r.user && r.user.theme) {
      appliquerTheme(r.user.theme);
      if (USER) {
        await api('/api/profile', { method: 'PUT', body: { theme: r.user.theme } }).catch(() => {});
      }
    }
    montrerApp();
  } catch (e) {
    $('login-error').textContent = e.message;
    $('login-error').classList.remove('hidden');
  } finally {
    btn.disabled = false;
  }
}

async function faireRegister(ev) {
  ev.preventDefault();
  const btn = $('btn-register');
  btn.disabled = true;
  $('login-error').classList.add('hidden');
  try {
    await api('/api/register', {
      method: 'POST',
      body: { username: $('reg-username').value, password: $('reg-password').value },
    });
    toast('Compte créé, vous pouvez vous connecter.');
    basculerOngletLogin('login');
  } catch (e) {
    $('login-error').textContent = e.message;
    $('login-error').classList.remove('hidden');
  } finally {
    btn.disabled = false;
  }
}

async function faireLogout() {
  try { await api('/api/logout', { method: 'POST' }); } catch (e) { /* ignore */ }
  USER = null;
  conversationId = null;
  location.href = '/';
}

function basculerOngletLogin(onglet) {
  const onglets = document.querySelectorAll('.login-tab');
  onglets.forEach((o) => o.classList.toggle('active', o.dataset.tab === onglet));
  $('form-login').classList.toggle('hidden', onglet !== 'login');
  $('form-register').classList.toggle('hidden', onglet !== 'register');
}

// ============================ Conversations ============================
async function chargerConversations() {
  if (!USER) return;
  try {
    CONVERSATIONS = await api('/api/conversations');
  } catch (e) { CONVERSATIONS = []; }
  rendreConvListe();
}

function rendreConvListe() {
  const liste = $('conv-list');
  liste.innerHTML = '';
  if (!CONVERSATIONS.length) {
    liste.innerHTML = '<div class="conv-empty-mini" style="color:var(--texte-doux);font-size:.82rem;padding:8px">Aucune conversation.</div>';
    return;
  }
  for (const c of CONVERSATIONS) {
    const item = document.createElement('div');
    item.className = 'conv-item' + (c.id === conversationId ? ' active' : '');
    item.innerHTML =
      '<span class="conv-item-title">' + esc(c.titre || 'Nouvelle conversation') + '</span>'
      + '<span class="conv-item-actions">'
      + '<button data-action="rename" title="Renommer">✏️</button>'
      + '<button data-action="delete" title="Supprimer">🗑</button>'
      + '</span>';
    item.addEventListener('click', (e) => {
      if (e.target.dataset.action === 'delete') { supprimerConversation(c.id); return; }
      if (e.target.dataset.action === 'rename') { renommerConversation(c.id, c.titre || 'Nouvelle conversation'); return; }
      ouvrirConversation(c.id);
    });
    liste.appendChild(item);
  }
}

function nouvelleConversation() {
  conversationId = null;
  modeEphemere = false;
  amphoreId = '';
  viderChat();
  rendreConvListe();
  if (USER) {
    const sel = $('select-amphore');
    if (sel) sel.value = '';
  }
  $('composer-input').focus();
  mettreAJourIndicateurEphemere();
}

function basculerModeEphemere() {
  modeEphemere = !modeEphemere;
  if (modeEphemere) {
    conversationId = null;
    amphoreId = '';
    viderChat();
    rendreConvListe();
    if (USER) {
      const sel = $('select-amphore');
      if (sel) sel.value = '';
    }
  }
  mettreAJourIndicateurEphemere();
  $('composer-input').focus();
}

function mettreAJourIndicateurEphemere() {
  const btn = $('btn-ephemeral');
  if (!btn) return;
  btn.classList.toggle('active', modeEphemere);
  btn.title = modeEphemere ? 'Quitter le mode éphémère' : 'Conversation éphémère (non sauvegardée)';
  const banniere = $('ephemeral-banner');
  if (banniere) banniere.classList.toggle('hidden', !modeEphemere);
}

async function ouvrirConversation(id) {
  if (modeEphemere) {
    modeEphemere = false;
    mettreAJourIndicateurEphemere();
  }
  if (!USER) return;
  conversationId = id;
  try {
    const conv = await api('/api/conversations/' + id);
    amphoreId = conv.amphore_id || '';
    viderChat();
    const sel = $('select-amphore');
    if (sel) sel.value = amphoreId;
    for (const m of conv.messages) {
      ajouterMessage(m.role, m.content, m.pieces_jointes || [], m.audio_b64 || null, m.id || null, m.meta || null);
    }
  } catch (e) {
    toast('Impossible de charger la conversation : ' + e.message);
  }
  rendreConvListe();
  defilerBas();
}

async function supprimerConversation(id) {
  if (!confirm('Supprimer cette conversation ?')) return;
  try {
    await api('/api/conversations/' + id, { method: 'DELETE' });
  } catch (e) { toast(e.message); return; }
  if (conversationId === id) {
    conversationId = null;
    viderChat();
  }
  chargerConversations();
}

async function renommerConversation(id, ancienTitre) {
  const nouveauTitre = prompt('Nouveau nom pour cette conversation :', ancienTitre);
  if (nouveauTitre === null || nouveauTitre.trim() === '') return;
  try {
    await api('/api/conversations/' + id, { method: 'PUT', body: { titre: nouveauTitre.trim() } });
    chargerConversations();
  } catch (e) { toast(e.message); }
}

function viderChat() {
  const zone = $('chat-messages');
  zone.innerHTML = '<div class="conv-empty" id="conv-empty"><div class="conv-empty-icon">📜</div><p>Posez votre question pour commencer.</p></div>';
}

// ============================ Amphores ============================
async function chargerAmphores() {
  try {
    AMPHORES = await api('/api/amphores');
  } catch (e) { AMPHORES = []; }
  rendreSelectAmphore();
}

function rendreSelectAmphore() {
  const zone = $('amphore-zone');
  if (CONFIG.amphores_mode !== 'user' && CONFIG.amphores_mode !== 'all') { zone.classList.add('hidden'); return; }
  zone.classList.remove('hidden');
  const sel = $('select-amphore');
  sel.innerHTML = '';
  const aucun = document.createElement('option');
  aucun.value = '';
  aucun.textContent = 'Aucun (prompt par défaut)';
  sel.appendChild(aucun);
  for (const a of AMPHORES) {
    const o = document.createElement('option');
    o.value = a.id;
    o.textContent = a.nom + (a.niveau === 'global' ? ' (globale)' : '');
    sel.appendChild(o);
  }
  sel.value = amphoreId || '';
  majBoutonSupprAmphore();
}

function majBoutonSupprAmphore() {
  const a = AMPHORES.find((x) => x.id === amphoreId);
  const peutSupprimer = USER && a && a.niveau === 'perso';
  $('btn-amphore-delete').classList.toggle('hidden', !peutSupprimer);
  const peutEditer = USER && a && a.niveau === 'perso';
  $('btn-amphore-edit').classList.toggle('hidden', !peutEditer);
}

function ouvrirModaleAmphore(amphore, global) {
  modalAmphoreId = amphore ? amphore.id : null;
  modalAmphoreGlobal = amphore ? amphore.niveau === 'global' : !!global;
  $('modal-amphore-title').textContent = amphore
    ? 'Éditer l\'amphore'
    : (modalAmphoreGlobal ? 'Nouvelle amphore globale' : 'Nouvelle amphore');
  $('amphore-name').value = amphore ? amphore.nom : '';
  $('amphore-description').value = amphore ? (amphore.description || '') : '';
  $('amphore-prompt').value = amphore ? (amphore.system_prompt || '') : '';
  $('amphore-error').classList.add('hidden');
  ouvrirModale('amphore');
}

async function creerAmphore(ev) {
  ev.preventDefault();
  const corps = {
    nom: $('amphore-name').value,
    description: $('amphore-description').value,
    system_prompt: $('amphore-prompt').value,
  };
  try {
    if (modalAmphoreId) {
      await api('/api/amphores/' + modalAmphoreId, { method: 'PUT', body: corps });
    } else {
      corps.global = modalAmphoreGlobal;
      await api('/api/amphores', { method: 'POST', body: corps });
    }
    fermerModale('amphore');
    chargerAmphores();
    if (USER && USER.is_admin && $('admin-panel') && $('admin-panel').classList.contains('open')) {
      chargerAmphoresAdmin();
    }
    toast('Amphore enregistrée.');
  } catch (e) {
    $('amphore-error').textContent = e.message;
    $('amphore-error').classList.remove('hidden');
  }
}

async function supprimerAmphore() {
  if (!amphoreId) return;
  if (!confirm('Supprimer cette amphore ?')) return;
  try {
    await api('/api/amphores/' + amphoreId, { method: 'DELETE' });
    amphoreId = '';
    chargerAmphores();
    toast('Amphore supprimée.');
  } catch (e) { toast(e.message); }
}

// ============================ Chat ============================
function viderSaisie() {
  $('composer-input').value = '';
  autoResize();
}

function defilerBas() {
  const z = $('chat-area');
  z.scrollTop = z.scrollHeight;
}

function retirerConvVide() {
  const v = $('conv-empty');
  if (v) v.remove();
}

function ajouterMessage(role, texte, pieces, audioB64 = null, messageId = null, meta = null) {
  retirerConvVide();
  const cont = $('chat-messages');
  const msg = document.createElement('div');
  msg.className = 'msg ' + role;
  const icone = role === 'user' ? '👤' : '📜';
  const nomMeta = role === 'user' ? (USER ? USER.username : 'Vous') : 'Hermes';
  const ttsHtml = (role === 'assistant' && CONFIG.tts_enabled) ? '<div class="msg-tts-left"></div>' : '';
  const infoHtml = (role === 'assistant' && CONFIG.tts_enabled) ? '<div class="msg-info-right"></div>' : '';
  msg.innerHTML =
    '<div class="msg-icon">' + icone + '</div>'
    + '<div class="msg-body">'
    + '<div class="msg-meta">' + esc(nomMeta) + '</div>'
    + (role === 'assistant' ? '<div class="msg-tools"></div>' : '')
    + '<div class="msg-content"></div>'
    + '<div class="msg-attachments"></div>'
    + '<div class="msg-footer">' + ttsHtml + infoHtml + '</div>'
    + '</div>';
  const contenu = msg.querySelector('.msg-content');
  if (role === 'user') {
    contenu.textContent = texte;
  } else {
    contenu.innerHTML = renderMarkdown(texte);
    // Extraire les liens de téléchargement du texte brut (avant rendu markdown)
    const pjZone = msg.querySelector('.msg-attachments');
    if (pjZone) {
      rendreLiensTelechargement(texte, pjZone);
    }
  }
  if (pieces && pieces.length) {
    rendrePieces(msg.querySelector('.msg-attachments'), pieces);
  }
  if (messageId) msg.dataset.messageId = messageId;
  if (role === 'assistant' && CONFIG.tts_enabled && texte) {
    ajouterTts(msg, texte, audioB64, meta);
  }
  cont.appendChild(msg);
  defilerBas();
  return msg;
}

function ajouterTts(msg, texte, audioB64 = null, meta = null) {
  const zoneInfo = msg.querySelector('.msg-info-right');
  const zoneTts = msg.querySelector('.msg-tts-left');
  if (!zoneInfo && !zoneTts) return;
  const dureeMs = (meta && meta.duree_ms) || msg.dataset.durationMs || null;
  if (zoneInfo && dureeMs) {
    const btnInfo = document.createElement('button');
    btnInfo.textContent = 'ℹ️';
    btnInfo.className = 'msg-info-btn';
    btnInfo.title = dureeMs + 's';
    zoneInfo.appendChild(btnInfo);
  }
  if (!zoneTts) return;
  const btnGen = document.createElement('button');
  btnGen.textContent = '🔊';
  btnGen.title = 'Générer le TTS';
  const btnPlay = document.createElement('button');
  btnPlay.textContent = '▶️';
  btnPlay.title = 'Écouter';
  const audioMap = new WeakMap();
  const info = { blob: null, audio: null };
  audioMap.set(btnPlay, info);
  const messageId = msg.dataset.messageId || '';
  if (audioB64) {
    const blob = base64ToBlob(audioB64);
    info.blob = blob;
    info.audio = new Audio(URL.createObjectURL(blob));
    info.audio.onended = () => { btnPlay.textContent = '▶️'; };
    btnPlay.disabled = false;
    btnGen.textContent = '🔄';
    btnGen.title = 'Régénérer le TTS';
  } else {
    btnPlay.disabled = true;
  }
  btnGen.addEventListener('click', () => {
    btnGen.disabled = true;
    btnGen.textContent = '⏳';
    const body = { texte };
    if (messageId) body.message_id = messageId;
    fetch('/api/tts', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
      .then((r) => { if (!r.ok) throw new Error('TTS'); return r.blob(); })
      .then((blob) => {
        info.blob = blob;
        info.audio = new Audio(URL.createObjectURL(blob));
        info.audio.onended = () => { btnPlay.textContent = '▶️'; };
        btnPlay.disabled = false;
        btnGen.textContent = '🔄';
        btnGen.title = 'Régénérer le TTS';
        btnGen.disabled = false;
      })
      .catch((e) => {
        btnGen.textContent = '🔊';
        btnGen.disabled = false;
        toast('Erreur TTS : ' + e.message);
      });
  });
  btnPlay.addEventListener('click', () => {
    if (!info.audio) return;
    if (info.audio.paused) {
      info.audio.play();
      btnPlay.textContent = '⏸️';
    } else {
      info.audio.pause();
      btnPlay.textContent = '▶️';
    }
  });
  zoneTts.appendChild(btnGen);
  zoneTts.appendChild(btnPlay);
}

function base64ToBlob(b64) {
  const byteChars = atob(b64);
  const byteNumbers = new Array(byteChars.length);
  for (let i = 0; i < byteChars.length; i++) {
    byteNumbers[i] = byteChars.charCodeAt(i);
  }
  return new Blob([new Uint8Array(byteNumbers)], { type: 'audio/mpeg' });
}

function rendrePieces(zone, pieces) {
  if (!zone) return;
  for (const p of pieces) {
    if (p.type === 'image' && p.b64) {
      const dataUri = 'data:' + (p.mime || 'image/png') + ';base64,' + p.b64;
      const img = document.createElement('img');
      img.src = dataUri;
      img.alt = p.nom || 'image';
      zone.appendChild(img);
      const a = document.createElement('a');
      a.className = 'attachment-chip';
      a.href = dataUri;
      a.download = p.nom || 'image.png';
      a.textContent = '⬇ ' + (p.nom || 'Télécharger l\'image');
      a.title = 'Télécharger l\'image';
      zone.appendChild(a);
    } else if (p.type === 'fichier' && p.b64) {
      const a = document.createElement('a');
      a.className = 'attachment-chip';
      a.href = 'data:' + (p.mime || 'application/octet-stream') + ';base64,' + p.b64;
      a.download = p.nom || 'fichier';
      a.textContent = '⬇ ' + (p.nom || 'Télécharger');
      zone.appendChild(a);
    } else if (p.type && p.nom) {
      // Fichier joint initial (image/audio/texte) : simple chip
      const span = document.createElement('span');
      span.className = 'attachment-chip';
      span.textContent = '📎 ' + p.nom;
      zone.appendChild(span);
    }
  }
}

/**
 * Extrait les liens markdown [texte](url) du texte et les affiche
 * comme des boutons de téléchargement dans la zone .msg-attachments.
 * La zone est vidée avant ajout pour éviter les doublons lors du streaming.
 */
function rendreLiensTelechargement(texte, pjZone) {
  pjZone.innerHTML = '';
  const lienRegex = /\[([^\]]+)\]\((\/api\/fichiers\/[^)]+)\)/g;
  let match;
  while ((match = lienRegex.exec(texte)) !== null) {
    const nomFichier = match[1];
    const urlFichier = match[2];
    const a = document.createElement('a');
    a.className = 'attachment-chip';
    a.href = urlFichier;
    a.download = nomFichier;
    a.textContent = '⬇ ' + nomFichier;
    pjZone.appendChild(a);
  }
}

function creerMessageAssistant() {
  const msg = ajouterMessage('assistant', '');
  // On repart sur un contenu vide prêt à recevoir les deltas.
  const contenu = msg.querySelector('.msg-content');
  contenu.innerHTML = '';
  return {
    msg,
    contenu,
    outils: msg.querySelector('.msg-tools'),
    pj: msg.querySelector('.msg-attachments'),
    texte: '',
    etape: null,
    raf: null,
  };
}

function majContenuIa(asst) {
  if (asst.raf) return;
  asst.raf = requestAnimationFrame(() => {
    asst.raf = null;
    asst.contenu.innerHTML = renderMarkdown(asst.texte) + '<span class="cursor"></span>';
    // Extraire les liens de téléchargement en temps réel pendant le streaming
    if (asst.pj && asst.texte) {
      rendreLiensTelechargement(asst.texte, asst.pj);
    }
    defilerBas();
  });
}

function nouveauOutilEl(asst, label, nom) {
  const el = document.createElement('div');
  el.className = 'tool';
  el.innerHTML =
    '<div class="tool-header"><span class="spinner"></span><span class="tool-label">'
    + esc(label) + '</span><span class="tool-step">en cours…</span></div>'
    + '<div class="tool-body"><pre></pre></div>';
  el.querySelector('.tool-header').addEventListener('click', () => el.classList.toggle('open'));
  asst.outils.appendChild(el);
  defilerBas();
  return el;
}

async function envoyer() {
  if (enCours) { arreterRequete(); return; }
  if (envoiEnAttente) return;
  // Envoi pendant dictée : on stoppe la dictée, on attend la
  // transcription, puis on envoie texte saisi + transcription.
  const btnDictee = $('btn-dictation');
  if (btnDictee.classList.contains('recording') || btnDictee.classList.contains('transcribing')) {
    envoiEnAttente = true;
    let dicteeTexte = null;
    try {
      dicteeTexte = await arreterDicteeEnAttente();
    } catch (e) {
      dicteeTexte = ''; // erreur : toast déjà affiché par transcrireDictee
    } finally {
      envoiEnAttente = false;
    }
    if (dicteeTexte === '' && $('composer-input').value.trim() !== '') {
      // La transcription n'a rien produit (erreur ou aucun texte détecté) :
      // pas d'envoi, le texte saisi reste dans la zone (dictée stoppée).
      $('composer-input').focus();
      return;
    }
    // dicteeTexte === null (trop court) ou texte non vide (déjà inséré
    // dans la zone par onstop) : on envoie la zone telle quelle.
  }
  const texte = $('composer-input').value.trim();
  if (!texte) return;
  enCours = true;
  idDemandeCourante = 'h_' + Math.random().toString(36).substring(2, 18) + Date.now().toString(36);
  controleurArret = new AbortController();
  basculerBoutonArret(true);
  viderSaisie();

  const fichier = fichierJoint;
  fichierJoint = null;
  masquerFichier();

  const pieceUser = fichier ? [{ nom: fichier.nom, type: 'fichier' }] : [];
  ajouterMessage('user', texte, pieceUser);

  const asst = creerMessageAssistant();
  asstCourant = asst;
  try {
    await streamChat(conversationId, texte, reasoningNiveau, amphoreId, fichier, controleurArret.signal, idDemandeCourante);
    finaliserAssistant(asst);
  } catch (e) {
    if (e.name === 'AbortError') {
      // Arrêt volontaire : on garde le texte partiel + le marqueur
      // (identique à ce que le serveur a sauvegardé), pas d'erreur affichée.
      asst.texte = (asst.texte ? asst.texte + '\n\n' : '') + MARQUEUR_ARRET;
      finaliserAssistant(asst);
    } else {
      asst.contenu.innerHTML = '<span style="color:var(--erreur)">⚠️ ' + esc(e.message) + '</span>';
    }
  } finally {
    asstCourant = null;
    enCours = false;
    controleurArret = null;
    idDemandeCourante = '';
    basculerBoutonArret(false);
    if (USER) chargerConversations();
  }
}

/** Bascule le bouton d'envoi ➤ en bouton stop ✘ (et inversement). */
function basculerBoutonArret(arret) {
  const btn = $('btn-send');
  btn.classList.toggle('stop', arret);
  btn.textContent = arret ? '✘' : '➤';
  btn.title = arret ? 'Arrêter la génération (Échap)' : 'Envoyer';
}

/** Demande l'arrêt de la requête en cours (bouton ✘ ou touche Échap). */
function arreterRequete() {
  if (!enCours) return;
  if (idDemandeCourante) {
    // Le serveur (un autre worker) voit le fichier data/stop/<id>.lock et
    // tue le transfer vers le LLM. En parallèle, on coupe le flux SSE local
    // pour figer le rendu immédiatement.
    api('/api/stop', { method: 'POST', body: { demande_id: idDemandeCourante } }).catch(() => {});
  }
  if (controleurArret) controleurArret.abort();
}

function finaliserAssistant(asst) {
  if (asst.raf) { cancelAnimationFrame(asst.raf); asst.raf = null; }
  asst.contenu.innerHTML = renderMarkdown(asst.texte);
  if (asst.etape) asst.etape.classList.add('hidden');
  defilerBas();
}

async function streamChat(convId, message, reasoning, amphore, fichier, signal, idDemande) {
  const debutRequete = Date.now();
  let body, headers = { credentials: 'include' };
  let uploadId = '';

  if (fichier) {
    if (fichier.file.size > CONFIG.seuil_upload_chunk) {
      const asst = asstCourant;
      if (asst && asst.etape) {
        asst.etape.classList.remove('hidden');
        asst.etape.textContent = '⏳ Préparation du fichier...';
      }

      uploadId = await uploaderChunks(fichier.file, (actuel, total) => {
        if (asst && asst.etape) {
          asst.etape.textContent = `⏳ Envoi du fichier : ${actuel}/${total} chunks`;
        }
      }, signal);

      if (asst && asst.etape) {
        asst.etape.textContent = '⏳ Traitement du fichier...';
      }
    }

    const fd = new FormData();
    fd.append('message', message);
    if (convId) fd.append('conversation_id', String(convId));
    if (reasoning) fd.append('reasoning', reasoning);
    if (amphore) fd.append('amphore_id', amphore);
    fd.append('ephemeral', modeEphemere ? '1' : '0');
    fd.append('debut', String(debutRequete));
    if (idDemande) fd.append('demande_id', idDemande);
    if (uploadId) {
      fd.append('upload_id', uploadId);
    } else {
      fd.append('fichier', fichier.file, fichier.nom);
    }
    body = fd;
  } else {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify({
      message,
      conversation_id: convId ? String(convId) : '',
      reasoning: reasoning || '',
      amphore_id: amphore || '',
      ephemeral: modeEphemere,
      debut: String(debutRequete),
      demande_id: idDemande || '',
    });
  }

  const res = await fetch('/api/chat', { method: 'POST', headers, body, signal });
  if (!res.ok || !(res.headers.get('content-type') || '').includes('text/event-stream')) {
    let msg = 'Erreur HTTP ' + res.status;
    try { const j = await res.json(); if (j.erreur) msg = j.erreur; } catch (e) { /* ignore */ }
    throw new Error(msg);
  }

  const reader = res.body.getReader();
  const dec = new TextDecoder();
  let buffer = '';
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += dec.decode(value, { stream: true });
    let idx;
    while ((idx = buffer.indexOf('\n\n')) !== -1) {
      const chunk = buffer.slice(0, idx);
      buffer = buffer.slice(idx + 2);
      handleSseChunk(chunk);
    }
  }
}

function handleSseChunk(chunk) {
  let evenement = 'message';
  let data = '';
  for (const ligne of chunk.split('\n')) {
    if (ligne.startsWith('event: ')) evenement = ligne.slice(7).trim();
    else if (ligne.startsWith('data: ')) data += ligne.slice(6);
  }
  let payload = {};
  try { payload = JSON.parse(data || '{}'); } catch (e) { /* ignore */ }
  onSse(evenement, payload);
}

// Le message assistant "en cours" (rempli par envoyer()).
let asstCourant = null;

function onSse(evenement, p) {
  if (!asstCourant) return;
  const asst = asstCourant;
  switch (evenement) {
    case 'conversation':
      conversationId = p.id;
      if (USER) chargerConversations();
      break;
    case 'etape': {
      if (!asst.etape) {
        asst.etape = document.createElement('div');
        asst.etape.className = 'step-status';
        asst.outils.parentNode.insertBefore(asst.etape, asst.outils);
      }
      asst.etape.textContent = '⏳ ' + p.texte;
      defilerBas();
      break;
    }
    case 'outil':
      asst._outilsEnCours = asst._outilsEnCours || [];
      asst._outilsEnCours.push({ nom: p.nom, el: nouveauOutilEl(asst, p.label, p.nom) });
      break;
    case 'outil_resultat': {
      const liste = asst._outilsEnCours || [];
      const i = liste.findIndex((o) => o.nom === p.nom && !o.fait);
      if (i >= 0) {
        const o = liste[i];
        o.fait = true;
        o.el.querySelector('.spinner').remove();
        o.el.querySelector('.tool-step').textContent = 'terminé';
        // Pour les fichiers générés, afficher le nom du fichier dans l'outil
        try {
          const parsed = JSON.parse(p.resultat);
          if (parsed.__fichier_genere__ && parsed.nom) {
            const zone = o.el.querySelector('.tool-body');
            zone.innerHTML = '';
            zone.textContent = parsed.nom;
            break;
          }
        } catch { /* pas du JSON, affichage texte normal */ }
        o.el.querySelector('.tool-body pre').textContent = p.resultat;
      }
      break;
    }
    case 'delta':
      if (asst.etape) asst.etape.classList.add('hidden');
      asst.texte += p.texte;
      majContenuIa(asst);
      break;
    case 'pieces_jointes':
      rendrePieces(asst.pj, p.pieces);
      defilerBas();
      break;
    case 'erreur':
      if (asst.etape) asst.etape.classList.add('hidden');
      asst.contenu.innerHTML = (asst.texte ? renderMarkdown(asst.texte) + '<br>' : '')
        + '<span style="color:var(--erreur)">⚠️ ' + esc(p.message) + '</span>';
      break;
    case 'fin':
      if (p.conversation_id) conversationId = p.conversation_id;
      if (p.message_id) asst.msg.dataset.messageId = p.message_id;
      if (p.duree_ms) {
        asst.msg.dataset.durationMs = p.duree_ms;
        asst.meta = asst.meta || {};
        asst.meta.duree_ms = p.duree_ms;
      }
      if (asst.texte && CONFIG.tts_enabled) ajouterTts(asst.msg, asst.texte, null, asst.meta);
      if (USER) chargerConversations();
      break;
  }
}

function jouerTts(texte) {
  toast('Synthèse vocale en cours…', 2000);
  fetch('/api/tts', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ texte }),
  })
    .then((r) => { if (!r.ok) throw new Error('TTS'); return r.blob(); })
    .then((blob) => new Audio(URL.createObjectURL(blob)).play())
    .catch((e) => toast('Erreur TTS : ' + e.message));
}

// ============================ Dictée vocale ============================
let mediaRecorder = null;   // MediaRecorder courant (null = inactif)
let mediaStream = null;     // flux micro courant
let dicteeChunks = [];      // chunks audio accumulés pendant l'enregistrement
let dicteeDebut = 0;        // timestamp (ms) du début de l'enregistrement
let dicteeTimerId = null;   // intervalle du compteur affiché
let dicteePromesse = null;  // transcription en cours (résout avec le texte,
                            // null = aucune transcription : pas d'audio / trop court)
let envoiEnAttente = false; // anti double-envoi pendant l'attente de la transcription
const DICTEE_MAX_MS = 5 * 60 * 1000; // 5 minutes maximum, arrêt automatique

/** MIME préféré pour MediaRecorder (feature detection par candidat). */
function dicteeMime() {
  const candidats = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm'];
  for (const mime of candidats) {
    try { if (MediaRecorder.isTypeSupported(mime)) return mime; } catch (e) { /* ignore */ }
  }
  return '';
}

/** Passe le bouton dictée dans un état : idle / recording / transcribing. */
function etatDictee(etat) {
  const btn = $('btn-dictation');
  btn.classList.remove('recording', 'transcribing');
  $('dictation-timer').classList.add('hidden');
  if (etat === 'recording') {
    btn.classList.add('recording');
    btn.title = 'Arrêter la dictée';
    $('dictation-icon').textContent = '⏺';
    $('dictation-timer').classList.remove('hidden');
  } else if (etat === 'transcribing') {
    btn.classList.add('transcribing');
    btn.title = 'Transcription en cours…';
    $('dictation-icon').textContent = '⏳';
  } else {
    btn.title = 'Dictée vocale';
    $('dictation-icon').textContent = '🎤';
  }
}

function majTimerDictee() {
  const secondes = Math.floor((Date.now() - dicteeDebut) / 1000);
  $('dictation-timer').textContent = Math.floor(secondes / 60) + ':' + String(secondes % 60).padStart(2, '0');
  if (Date.now() - dicteeDebut >= DICTEE_MAX_MS) arreterDictee();
}

function libererStreamMicro() {
  if (mediaStream) {
    for (const t of mediaStream.getTracks()) t.stop();
    mediaStream = null;
  }
  if (dicteeTimerId) {
    clearInterval(dicteeTimerId);
    dicteeTimerId = null;
  }
}

async function basculerDictee() {
  const btn = $('btn-dictation');
  if (btn.classList.contains('transcribing')) return;
  if (btn.classList.contains('recording')) { arreterDictee(); return; }
  try {
    const mime = dicteeMime();
    mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    mediaRecorder = new MediaRecorder(mediaStream, mime ? { mimeType: mime } : undefined);
    dicteeChunks = [];
    mediaRecorder.ondataavailable = (e) => {
      if (e.data && e.data.size > 0) dicteeChunks.push(e.data);
    };
    mediaRecorder.onstop = () => {
      libererStreamMicro();
      etatDictee('idle');
      if (dicteeChunks.length === 0) {
        dicteePromesse = Promise.resolve(null);
        return;
      }
      const blob = new Blob(dicteeChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
      dicteeChunks = [];
      if (blob.size < 1000) {
        toast('Enregistrement trop court.');
        dicteePromesse = Promise.resolve(null);
        return;
      }
      dicteePromesse = transcrireDictee(blob);
      // Chemin normal (arrêt au bouton micro) : on insère la transcription
      // dans la zone de saisie. « Envoyer pendant dictée » (envoyer())
      // consomme la même promesse et lit la zone après coup.
      dicteePromesse
        .then((t) => { if (t) ajouterTexteDictee(t); })
        .catch(() => { /* le toast d'erreur est affiché par transcrireDictee */ });
    };
    mediaRecorder.start(250);
    dicteeDebut = Date.now();
    etatDictee('recording');
    $('dictation-timer').textContent = '0:00';
    dicteeTimerId = setInterval(majTimerDictee, 250);
    $('composer-input').focus();
  } catch (e) {
    libererStreamMicro();
    etatDictee('idle');
    toast('Impossible d\'accéder au micro : ' + e.message);
  }
}

function arreterDictee() {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop(); // onstop s'occupe de la transcription
  } else {
    libererStreamMicro();
    etatDictee('idle');
  }
}

/**
 * Stoppe la dictée (si elle enregistre) et renvoie une promesse résolue
 * avec le résultat de la transcription :
 *   - chaîne (éventuellement vide) : transcription faite (vide = rien détecté)
 *   - null : aucune transcription (pas d'audio ou enregistrement trop court)
 *   - rejet : erreur de transcription
 * Si une transcription est déjà en cours, attend simplement sa fin.
 */
function arreterDicteeEnAttente() {
  const btn = $('btn-dictation');
  if (btn.classList.contains('transcribing')) {
    return dicteePromesse || Promise.resolve(null);
  }
  if (btn.classList.contains('recording') && mediaRecorder) {
    return new Promise((resolve) => {
      const ancien = mediaRecorder.onstop;
      // onstop (handler synchrone) met à jour dicteePromesse avant le resolve.
      mediaRecorder.onstop = () => {
        ancien();
        resolve(dicteePromesse);
      };
      if (mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
      }
      // Si l'état est déjà « inactive » (stop en cours), onstop se
      // déclenchera de lui-même et la promesse sera résolue.
    });
  }
  return Promise.resolve(null);
}

/**
 * Envoie un enregistrement à /api/dictation.
 * Renvoie une promesse résolue avec le texte transcrit (chaîne vide si
 * rien n'a été détecté) ; elle rejette en cas d'erreur (le toast est
 * affiché ici). L'insertion dans la zone de saisie est faite par
 * l'appelant (onstop).
 */
function transcrireDictee(blob) {
  etatDictee('transcribing');
  const mime = blob.type || '';
  const ext = mime.includes('mp4') || mime.includes('aac') ? 'm4a'
    : mime.includes('ogg') ? 'ogg' : 'webm';
  const fd = new FormData();
  fd.append('fichier', blob, 'diction.' + ext);
  return api('/api/dictation', { method: 'POST', body: fd })
    .then((res) => {
      const texte = String(res.texte || '').trim();
      if (!texte) {
        toast('Aucun texte détecté dans l\'enregistrement.');
      }
      return texte;
    })
    .catch((e) => {
      toast('Dictée : ' + e.message);
      throw e;
    })
    .finally(() => etatDictee('idle'));
}

/** Insère le texte dicté dans la zone de saisie (éditable avant envoi). */
function ajouterTexteDictee(texte) {
  const ta = $('composer-input');
  const actuel = ta.value;
  const prefixe = (actuel !== '' && !/\s$/.test(actuel)) ? ' ' : '';
  ta.value = actuel + prefixe + texte;
  autoResize();
  ta.focus();
  ta.selectionStart = ta.selectionEnd = ta.value.length;
}

// ============================ Fichier joint ============================
function choisirFichier() {
  const inp = $('file-input');
  inp.addEventListener('change', () => {
    const f = inp.files[0];
    if (!f) return;
    fichierJoint = { file: f, nom: f.name, mime: f.type };
    $('file-name').textContent = f.name;
    $('file-icon').textContent = iconeTypeFichier(f.type);
    $('file-preview').classList.remove('hidden');
    inp.value = '';
  }, { once: true });
  inp.click();
}

function iconeTypeFichier(mime) {
  if (!mime) return '📄';
  if (mime.startsWith('image/')) return '🖼';
  if (mime.startsWith('audio/')) return '🎵';
  if (mime.startsWith('video/')) return '🎬';
  if (mime.includes('pdf')) return '📕';
  if (mime.includes('sheet') || mime.includes('excel') || mime.includes('csv')) return '📊';
  if (mime.includes('word') || mime.includes('document')) return '📝';
  return '📄';
}

function masquerFichier() {
  $('file-preview').classList.add('hidden');
}

// ============================ Modales ============================
let modalZIndex = 1000;
function ouvrirModale(nom) {
  const el = $('modal-' + nom);
  modalZIndex++;
  el.style.zIndex = modalZIndex;

  // Fermer les menus avant d'afficher la modale
  $('sidebar').classList.remove('open');
  $('sidebar-overlay').classList.remove('active');
  const menuUser = $('user-menu');
  if (menuUser) menuUser.classList.add('hidden');

  // Petit délai pour s'assurer que les menus sont fermés avant l'affichage
  requestAnimationFrame(() => {
    el.classList.remove('hidden');
  });
}
function fermerModale(nom) { $('modal-' + nom).classList.add('hidden'); }

async function changerMotDePasse(ev) {
  ev.preventDefault();
  $('profile-error').classList.add('hidden');
  try {
    const res = await api('/api/password', {
      method: 'POST',
      body: { actuel: $('password-current').value, nouveau: $('password-new').value },
    });
    $('form-password').reset();
    fermerModale('profil');
    toast(res.message || 'Mot de passe modifié.');
  } catch (e) {
    $('profile-error').textContent = e.message;
    $('profile-error').classList.remove('hidden');
  }
}

// ============================ Administration ============================
async function ouvrirAdmin() {
  ouvrirModale('admin');
  chargerUsers();
}

async function chargerUsers() {
  let users;
  try { users = await api('/api/users'); } catch (e) { toast(e.message); return; }
  const liste = $('admin-list-users');
  liste.innerHTML = '';
  for (const u of users) {
    const ligne = document.createElement('div');
    ligne.className = 'admin-row';
    ligne.innerHTML =
      '<span class="name">' + esc(u.username) + '</span>'
      + (u.is_admin ? '<span class="badge admin">admin</span>' : '')
      + (u.is_active ? '' : '<span class="badge inactive">inactif</span>')
      + (u.auth_provider && u.auth_provider !== 'local' ? '<span class="badge">' + esc(u.auth_provider) + '</span>' : '');
    const selAdmin = document.createElement('select');
    selAdmin.title = 'Statut admin';
    selAdmin.innerHTML = '<option value="0">user</option><option value="1">admin</option>';
    selAdmin.value = u.is_admin ? '1' : '0';
    selAdmin.addEventListener('change', async () => {
      try { await api('/api/users/' + u.id, { method: 'PUT', body: { is_admin: selAdmin.value === '1' } }); chargerUsers(); }
      catch (e) { toast(e.message); }
    });
    const selActif = document.createElement('select');
    selActif.title = 'Actif';
    selActif.innerHTML = '<option value="1">actif</option><option value="0">désactivé</option>';
    selActif.value = u.is_active ? '1' : '0';
    selActif.addEventListener('change', async () => {
      try { await api('/api/users/' + u.id, { method: 'PUT', body: { is_active: selActif.value === '1' } }); chargerUsers(); }
      catch (e) { toast(e.message); }
    });
    const btnMdp = document.createElement('button');
    btnMdp.className = 'btn btn-tiny';
    btnMdp.textContent = 'Réinitialiser MD';
    btnMdp.addEventListener('click', async () => {
      const mdp = prompt('Nouveau mot de passe pour ' + u.username + ' :');
      if (!mdp) return;
      try { await api('/api/users/' + u.id, { method: 'PUT', body: { password: mdp } }); toast('Mot de passe réinitialisé.'); }
      catch (e) { toast(e.message); }
    });
    ligne.appendChild(selAdmin);
    ligne.appendChild(selActif);
    ligne.appendChild(btnMdp);
    liste.appendChild(ligne);
  }
}

async function creerUser(ev) {
  ev.preventDefault();
  try {
    await api('/api/users', {
      method: 'POST',
      body: { username: $('user-create-name').value, password: $('user-create-password').value },
    });
    $('form-user-create').reset();
    chargerUsers();
  } catch (e) { toast(e.message); }
}

async function chargerAmphoresAdmin() {
  let amp;
  try { amp = await api('/api/amphores'); } catch (e) { return; }
  const liste = $('admin-list-amphores');
  liste.innerHTML = '';
  // L'admin gère les amphores GLOBALES (les perso restent chez chaque user).
  const globales = amp.filter((a) => a.niveau === 'global');
  if (!globales.length) {
    liste.innerHTML = '<p class="admin-empty">Aucune amphore globale.</p>';
    return;
  }
  for (const a of globales) {
    const ligne = document.createElement('div');
    ligne.className = 'admin-row';
    ligne.innerHTML = '<span class="name">' + esc(a.nom) + '</span>';
    const btnEditer = document.createElement('button');
    btnEditer.className = 'btn btn-tiny';
    btnEditer.textContent = 'Éditer';
    btnEditer.addEventListener('click', () => ouvrirModaleAmphore(a));
    const btnSuppr = document.createElement('button');
    btnSuppr.className = 'btn btn-tiny';
    btnSuppr.textContent = '🗑';
    btnSuppr.addEventListener('click', async () => {
      if (!confirm('Supprimer l\'amphore globale « ' + a.nom + ' » ?')) return;
      try { await api('/api/amphores/' + a.id, { method: 'DELETE' }); chargerAmphoresAdmin(); chargerAmphores(); }
      catch (e) { toast(e.message); }
    });
    ligne.appendChild(btnEditer);
    ligne.appendChild(btnSuppr);
    liste.appendChild(ligne);
  }
}

// ============================ Bindings ============================
function lierEvenements() {
  // Login
  $('form-login').addEventListener('submit', faireLogin);
  $('form-register').addEventListener('submit', faireRegister);
  document.querySelectorAll('.login-tab').forEach((o) =>
    o.addEventListener('click', () => basculerOngletLogin(o.dataset.tab)));
  $('btn-login-oidc').addEventListener('click', () => { location.href = '/api/oidc/start'; });

  // Sidebar
  $('btn-new-conv').addEventListener('click', nouvelleConversation);
  $('btn-ephemeral').addEventListener('click', basculerModeEphemere);
  $('form-password').addEventListener('submit', changerMotDePasse);

  // Mobile menu
  if ($('btn-mobile-menu')) {
    $('btn-mobile-menu').addEventListener('click', () => {
      $('sidebar').classList.toggle('open');
      $('sidebar-overlay').classList.toggle('active');
    });
  }
  $('sidebar-overlay').addEventListener('click', () => {
    $('sidebar').classList.remove('open');
    $('sidebar-overlay').classList.remove('active');
  });

  // Thème
  $('opt-light').addEventListener('click', () => changerTheme('light'));
  $('opt-dark').addEventListener('click', () => changerTheme('dark'));

  // Amphores
  $('btn-amphore-create').addEventListener('click', () => ouvrirModaleAmphore(null, false));
  $('btn-amphore-edit').addEventListener('click', () => {
    const a = AMPHORES.find((x) => x.id === amphoreId);
    if (a) ouvrirModaleAmphore(a, false);
  });
  $('btn-amphore-delete').addEventListener('click', supprimerAmphore);
  $('select-amphore').addEventListener('change', (e) => {
    amphoreId = e.target.value;
    majBoutonSupprAmphore();
    if (USER && conversationId) {
      api('/api/conversations/' + conversationId, { method: 'PUT', body: { amphore_id: amphoreId } }).catch(() => {});
    }
  });
  $('form-amphore').addEventListener('submit', creerAmphore);
  $('btn-reasoning').addEventListener('click', (e) => {
    e.stopPropagation();
    if (reasoningNiveaux.length === 0) return;
    $('reasoning-menu').classList.toggle('hidden');
    majMenuRais();
  });
  $('reasoning-menu').addEventListener('click', (e) => {
    const item = e.target.closest('.menu-item');
    if (!item) return;
    reasoningNiveau = item.dataset.level;
    $('reasoning-menu').classList.add('hidden');
    majMenuRais();
    const labels = { none: 'Aucun', minimal: 'Minimal', low: 'Faible', medium: 'Moyen', high: 'Élevé', xhigh: 'Très élevé', user: 'Utilisateur', '': 'Par défaut' };
    $('reasoning-label').textContent = labels[reasoningNiveau] || reasoningNiveau;
  });
  document.addEventListener('click', (e) => {
    const menuRais = $('reasoning-menu');
    if (!menuRais.contains(e.target) && e.target !== $('btn-reasoning')) {
      menuRais.classList.add('hidden');
    }
    const menuUser = $('user-menu');
    if (!menuUser.contains(e.target) && e.target !== $('user-info-menu')) {
      menuUser.classList.add('hidden');
    }
  });

  $('user-info-menu').addEventListener('click', (e) => {
    e.stopPropagation();
    $('user-menu').classList.toggle('hidden');
  });

  // Chat
  $('btn-send').addEventListener('click', envoyer);
  const ta = $('composer-input');
  ta.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); envoyer(); }
  });
  ta.addEventListener('input', autoResize);
  // Échap : stop de la génération en cours (même que le bouton ✘).
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && enCours) arreterRequete();
  });

  // Fichier
  $('btn-clip').addEventListener('click', choisirFichier);
  $('btn-file-remove').addEventListener('click', () => { fichierJoint = null; masquerFichier(); });

  // Dictée vocale
  $('btn-dictation').addEventListener('click', basculerDictee);

  // Modales
  document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => fermerModale(b.dataset.close)));

  // Admin
  $('form-user-create').addEventListener('submit', creerUser);
  $('btn-amphore-global-create').addEventListener('click', () => ouvrirModaleAmphore(null, true));
  document.querySelectorAll('.admin-tab').forEach((o) =>
    o.addEventListener('click', () => {
      document.querySelectorAll('.admin-tab').forEach((x) => x.classList.toggle('active', x === o));
      $('admin-panel-users').classList.toggle('hidden', o.dataset.adminTab !== 'users');
      $('admin-panel-amphores').classList.toggle('hidden', o.dataset.adminTab !== 'amphores');
      if (o.dataset.adminTab === 'users') chargerUsers();
      if (o.dataset.adminTab === 'amphores') chargerAmphoresAdmin();
    }));
}

function autoResize() {
  const ta = $('composer-input');
  ta.style.height = 'auto';
  ta.style.height = Math.min(ta.scrollHeight, 200) + 'px';
}

// ============================ Config système ============================
let configData = null;
let configOngletActif = null;

function ouvrirModaleConfig() {
  configData = null;
  configOngletActif = null;
  $('config-panel').innerHTML = '<em>Chargement…</em>';
  ouvrirModale('config');
  chargerConfig();
}

async function chargerConfig() {
  try {
    configData = await api('/api/admin/config');
    rendreConfigUnifiee();
  } catch (e) {
    $('config-panel').innerHTML = '<span style="color:var(--erreur)">Erreur chargement config.</span>';
  }
}

function rendreConfigUnifiee() {
  const cont = $('config-panel');
  cont.innerHTML = '';
  const sections = Object.keys(configData);
  sections.forEach((sec) => {
    const titre = document.createElement('h3');
    titre.className = 'config-section-title';
    titre.textContent = sec;
    cont.appendChild(titre);
    const data = configData[sec];
    if (typeof data !== 'object' || data === null || Array.isArray(data)) {
      const p = document.createElement('p');
      p.className = 'modal-info';
      p.textContent = 'Section non structurée.';
      cont.appendChild(p);
      return;
    }
    const form = document.createElement('div');
    form.className = 'config-form';
    for (const [cle, valeur] of Object.entries(data)) {
      if (cle.endsWith('_options')) continue;
      const groupe = document.createElement('div');
      groupe.className = 'config-group';
      const label = document.createElement('label');
      label.textContent = cle;
      groupe.appendChild(label);
      if (typeof valeur === 'boolean' || valeur === 'true' || valeur === 'false') {
        const radioGroupe = document.createElement('div');
        radioGroupe.className = 'config-radio';
        const vrai = document.createElement('input');
        vrai.type = 'radio';
        vrai.name = 'config-' + sec + '-' + cle;
        vrai.value = 'true';
        vrai.id = 'config-' + sec + '-' + cle + '-vrai';
        vrai.checked = valeur === true || valeur === 'true';
        const lblVrai = document.createElement('span');
        lblVrai.textContent = 'Oui';
        const faux = document.createElement('input');
        faux.type = 'radio';
        faux.name = 'config-' + sec + '-' + cle;
        faux.value = 'false';
        faux.id = 'config-' + sec + '-' + cle + '-faux';
        faux.checked = valeur === false || valeur === 'false';
        const lblFaux = document.createElement('span');
        lblFaux.textContent = 'Non';
        radioGroupe.appendChild(vrai);
        radioGroupe.appendChild(lblVrai);
        radioGroupe.appendChild(faux);
        radioGroupe.appendChild(lblFaux);
        groupe.appendChild(radioGroupe);
      } else if (cle.endsWith('_options')) {
        // Skip options metadata
      } else {
        const optionsKey = cle + '_options';
        const options = data[optionsKey];
        if (Array.isArray(options) && options.length > 0) {
          const select = document.createElement('select');
          select.id = 'config-' + sec + '-' + cle;
          options.forEach(opt => {
            const optionEl = document.createElement('option');
            optionEl.value = opt;
            optionEl.textContent = opt;
            optionEl.selected = String(opt) === String(valeur);
            select.appendChild(optionEl);
          });
          groupe.appendChild(select);
        } else if (Array.isArray(valeur)) {
          const select = document.createElement('select');
          select.multiple = true;
          select.size = Math.min(valeur.length, 5);
          select.id = 'config-' + sec + '-' + cle;
          select.dataset.original = JSON.stringify(valeur);
          valeur.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v;
            opt.textContent = v;
            opt.selected = true;
            select.appendChild(opt);
          });
          groupe.appendChild(select);
        } else if (typeof valeur === 'number') {
          const inp = document.createElement('input');
          inp.type = 'number';
          inp.value = valeur;
          inp.id = 'config-' + sec + '-' + cle;
          groupe.appendChild(inp);
        } else {
          const inp = document.createElement('input');
          inp.type = 'text';
          inp.value = valeur;
          inp.id = 'config-' + sec + '-' + cle;
          if (cle.endsWith('_secret') || cle === 'api_key') inp.type = 'password';
          groupe.appendChild(inp);
        }
      }
      form.appendChild(groupe);
    }
    cont.appendChild(form);
  });
}

$('btn-config-save').addEventListener('click', async () => {
  const btn = $('btn-config-save');
  btn.disabled = true;
  btn.textContent = 'Sauvegarde…';
  try {
    const nouvelleConfig = JSON.parse(JSON.stringify(configData));
    for (const section of Object.keys(nouvelleConfig)) {
      const data = nouvelleConfig[section];
      if (typeof data !== 'object' || data === null || Array.isArray(data)) continue;
      for (const cle of Object.keys(data)) {
        if (cle.endsWith('_options')) continue;
        const el = $('config-' + section + '-' + cle);
        if (!el) continue;
        if (el.tagName === 'SELECT') {
          if (el.multiple) {
            nouvelleConfig[section][cle] = Array.from(el.selectedOptions).map(o => o.value);
          } else {
            nouvelleConfig[section][cle] = el.value;
          }
        } else if (el.type === 'radio') {
          const checked = document.querySelector('input[name="config-' + section + '-' + cle + '"]:checked');
          nouvelleConfig[section][cle] = checked ? checked.value === 'true' : false;
        } else {
          const val = el.value;
          nouvelleConfig[section][cle] = el.type === 'number' ? (val === '' ? 0 : parseFloat(val)) : val;
        }
      }
    }
    await api('/api/admin/config', { method: 'POST', body: { config: nouvelleConfig } });
    toast('Config sauvegardée.');
    fermerModale('config');
  } catch (e) {
    toast('Erreur sauvegarde : ' + e.message);
  }
  btn.disabled = false;
  btn.textContent = 'Sauvegarder';
});

// ============================ Démarrage ============================
lierEvenements();
init();
