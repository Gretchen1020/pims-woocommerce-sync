
const $ = (s, el=document) => el.querySelector(s);
const $$ = (s, el=document) => [...el.querySelectorAll(s)];
let csrf = '';
let user = null;
let view = 'products';
let state = { products: [], categories: [], attributes: [], editing: null, resolve: false };

async function api(method, path, body) {
  const opts = { method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
  if (body !== undefined) {
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = csrf;
    opts.body = JSON.stringify(body);
  } else if (method !== 'GET') {
    opts.headers['X-CSRF-Token'] = csrf;
  }
  const res = await fetch(path, opts);
  const data = await res.json().catch(() => ({}));
  if (data.csrf_token) csrf = data.csrf_token;
  if (!res.ok) {
    const msg = data.error?.message || res.statusText;
    const err = new Error(msg);
    err.status = res.status;
    err.fields = data.error?.fields;
    err.code = data.error?.code;
    throw err;
  }
  return data;
}

function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

async function init() {
  try {
    const data = await api('GET', '/api/auth/me');
    csrf = data.csrf_token || '';
    user = data.user;
    if (user) showApp(); else showLogin();
  } catch { showLogin(); }
}

function showLogin() {
  $('#hdr').classList.add('hidden');
  $('#app').innerHTML = `
    <div class="login-wrap card">
      <h2>Sign in to PIMS</h2>
      <form id="login-form">
        <label>Email</label><input type="email" name="email" required autocomplete="username">
        <label>Password</label><input type="password" name="password" required autocomplete="current-password">
        <div class="error hidden" id="login-err"></div>
        <button class="btn primary" style="width:100%;margin-top:0.5rem">Login</button>
      </form>
    </div>`;
  $('#login-form').onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      const data = await api('POST', '/api/auth/login', { email: fd.get('email'), password: fd.get('password') });
      user = data.user; csrf = data.csrf_token; showApp();
    } catch (err) {
      const el = $('#login-err'); el.textContent = err.message; el.classList.remove('hidden');
    }
  };
}

function showApp() {
  $('#hdr').classList.remove('hidden');
  $('#user-email').textContent = user.email;
  $$('nav button').forEach(b => {
    b.classList.toggle('active', b.dataset.view === view);
    b.onclick = () => { view = b.dataset.view; state.editing = null; state.resolve = false; render(); };
  });
  $('#btn-logout').onclick = async () => { await api('POST', '/api/auth/logout'); user = null; showLogin(); };
  render();
}

async function render() {
  const app = $('#app');
  app.innerHTML = '<p class="muted">Loading…</p>';
  try {
    if (view === 'products') await renderProducts(app);
    else if (view === 'categories') await renderCategories(app);
    else if (view === 'attributes') await renderAttributes(app);
    else if (view === 'logs') await renderLogs(app);
  } catch (err) {
    app.innerHTML = `<div class="error">${esc(err.message)}</div>`;
  }
}

async function renderProducts(app) {
  const [plist, clist] = await Promise.all([
    api('GET', '/api/products?limit=50'),
    api('GET', '/api/categories'),
  ]);
  state.products = plist.data || [];
  state.categories = clist.data || [];

  if (state.editing === 'new' || (state.editing && typeof state.editing === 'number')) {
    return renderProductForm(app, state.editing === 'new' ? null : state.editing);
  }

  app.innerHTML = `
    <div class="toolbar">
      <button class="btn primary" id="btn-new-product">+ New product</button>
      <button class="btn" id="btn-import">Import from WooCommerce</button>
    </div>
    <div class="card" style="padding:0;overflow:auto">
      <table>
        <thead><tr><th>SKU</th><th>Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Sync</th><th></th></tr></thead>
        <tbody id="prod-tbody">
          ${state.products.map(p => `
            <tr data-id="${p.id}">
              <td><code>${esc(p.sku)}</code></td>
              <td>${esc(p.name)}</td>
              <td class="muted">${esc(p.category_name || '')}</td>
              <td>${esc(p.price)}</td>
              <td>${esc(p.stock)}</td>
              <td><span class="badge ${esc(p.status)}">${esc(p.status)}</span></td>
              <td><span class="badge ${esc(p.sync_status)}">${esc(p.sync_status)}</span></td>
              <td class="actions">
                <button class="btn sm" data-act="edit">Edit</button>
                <button class="btn sm ok" data-act="sync">Sync</button>
                ${p.sync_status === 'CONFLICT' ? '<button class="btn sm" data-act="resolve" style="border-color:var(--conflict);color:var(--conflict)">Resolve</button>' : ''}
                <button class="btn sm danger" data-act="del">Del</button>
              </td>
            </tr>`).join('') || '<tr><td colspan="8" class="empty">No products yet</td></tr>'}
        </tbody>
      </table>
    </div>`;

  $('#btn-new-product').onclick = () => { state.editing = 'new'; render(); };
  $('#btn-import').onclick = async () => {
    if (!confirm('Import all products from WooCommerce?')) return;
    try {
      const r = await api('POST', '/api/sync/import');
      alert('Import done: created ' + r.data.created + ', updated ' + r.data.updated + ', skipped ' + r.data.skipped + ', errors ' + r.data.errors);
      render();
    } catch (e) { alert(e.message); }
  };
  $('#prod-tbody').onclick = async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const id = +btn.closest('tr').dataset.id;
    const act = btn.dataset.act;
    if (act === 'edit') { state.editing = id; render(); }
    else if (act === 'sync') {
      btn.disabled = true; btn.textContent = '…';
      try { await api('POST', '/api/products/' + id + '/sync'); render(); }
      catch (err) { alert(err.message); btn.disabled = false; btn.textContent = 'Sync'; }
    } else if (act === 'resolve') { state.editing = id; state.resolve = true; render(); }
    else if (act === 'del') {
      if (!confirm('Delete / archive this product?')) return;
      try { await api('DELETE', '/api/products/' + id); render(); } catch (err) { alert(err.message); }
    }
  };
}

async function renderProductForm(app, id) {
  let product = null;
  if (id) {
    const r = await api('GET', '/api/products/' + id);
    product = r.data;
  }
  const cats = state.categories.length ? state.categories : (await api('GET', '/api/categories')).data;
  state.categories = cats;
  const catId = product?.category_id || (cats[0]?.id ?? '');
  let catAttrs = [];
  if (catId) {
    try { catAttrs = (await api('GET', '/api/categories/' + catId)).data.attributes || []; } catch {}
  }
  const attrVals = {};
  (product?.attributes || []).forEach(a => { attrVals[a.attribute_id] = a.value; });

  const isConflict = product?.sync_status === 'CONFLICT' && state.resolve;
  let conflictHtml = '';
  if (isConflict && product.conflict_fields) {
    const cf = typeof product.conflict_fields === 'string' ? JSON.parse(product.conflict_fields) : product.conflict_fields;
    conflictHtml = '<div class="card" style="border-color:var(--conflict)"><h2>Resolve conflicts</h2>' +
      Object.entries(cf).map(([f, v]) => `
        <div style="margin-bottom:0.75rem">
          <strong>${esc(f)}</strong>
          <div class="form-row">
            <label><input type="radio" name="choice_${esc(f)}" value="pims" checked> PIMS: <code>${esc(JSON.stringify(v.pims))}</code></label>
            <label><input type="radio" name="choice_${esc(f)}" value="woocommerce"> WooCommerce: <code>${esc(JSON.stringify(v.woocommerce))}</code></label>
          </div>
        </div>`).join('') +
      `<div class="actions">
        <button class="btn primary" id="btn-resolve">Apply &amp; re-sync</button>
        <button class="btn" id="btn-keep-pims">Keep all PIMS</button>
        <button class="btn" id="btn-keep-wc">Keep all WooCommerce</button>
        <button class="btn" id="btn-cancel-resolve">Cancel</button>
      </div></div>`;
  }

  app.innerHTML = conflictHtml + `
    <div class="card">
      <h2>${product ? 'Edit product' : 'New product'}</h2>
      <form id="prod-form">
        <div class="form-row">
          <div><label>SKU</label><input name="sku" value="${esc(product?.sku||'')}" ${product?.woocommerce_id ? 'readonly' : 'required'}></div>
          <div><label>Name</label><input name="name" value="${esc(product?.name||'')}" required maxlength="255"></div>
        </div>
        <label>Description</label><textarea name="description">${esc(product?.description||'')}</textarea>
        <div class="form-row">
          <div><label>Category</label>
            <select name="category_id" id="sel-cat">${cats.map(c => `<option value="${c.id}" ${c.id==catId?'selected':''}>${esc(c.name)}</option>`).join('')}</select>
          </div>
          <div><label>Brand</label><input name="brand" value="${esc(product?.brand||'')}"></div>
        </div>
        <div class="form-row">
          <div><label>Price</label><input name="price" type="number" step="0.01" min="0" value="${esc(product?.price??'')}" required></div>
          <div><label>Stock ${product?.woocommerce_id ? '(read-only after sync)' : ''}</label>
            <input name="stock" type="number" min="0" value="${esc(product?.stock??0)}" ${product?.woocommerce_id ? 'readonly' : ''}></div>
        </div>
        <div class="form-row">
          <div><label>Status</label>
            <select name="status">
              ${['draft','active','inactive'].map(s => `<option ${product?.status===s?'selected':''}>${s}</option>`).join('')}
            </select>
          </div>
          <div>${product ? `<label>Sync status</label><div><span class="badge ${esc(product.sync_status)}">${esc(product.sync_status)}</span>
            ${product.woocommerce_id ? '<button type="button" class="btn sm" id="btn-refresh-stock" style="margin-left:0.5rem">Refresh stock</button>' : ''}</div>` : ''}</div>
        </div>
        <div id="attr-fields">
          <h2 style="margin-top:1rem;font-size:0.95rem">Attributes</h2>
          ${catAttrs.map(a => {
            const val = attrVals[a.attribute_id] ?? '';
            let input = '';
            if (a.data_type === 'boolean') {
              input = `<select name="attr_${a.attribute_id}"><option value="">—</option>
                <option value="1" ${val===true||val===1?'selected':''}>Yes</option>
                <option value="0" ${val===false||val===0?'selected':''}>No</option></select>`;
            } else if (a.data_type === 'select') {
              input = `<input name="attr_${a.attribute_id}" value="${esc(val)}" placeholder="option id">`;
            } else if (a.data_type === 'number' || a.data_type === 'unit') {
              input = `<input name="attr_${a.attribute_id}" type="number" step="any" value="${esc(val)}" ${a.unit?'placeholder="'+esc(a.unit)+'"':''}>`;
            } else {
              input = `<input name="attr_${a.attribute_id}" value="${esc(val)}">`;
            }
            return `<label>${esc(a.name)}${a.required==1?' *':''} <span class="muted">(${esc(a.data_type)})</span></label>${input}`;
          }).join('') || '<p class="muted">No attributes assigned to this category.</p>'}
        </div>
        <div class="error hidden" id="form-err"></div>
        <div class="actions" style="margin-top:1rem">
          <button class="btn primary" type="submit">Save</button>
          <button class="btn" type="button" id="btn-cancel">Cancel</button>
        </div>
        ${product ? `<input type="hidden" name="version" value="${product.version}">` : ''}
      </form>
    </div>`;

  for (const a of catAttrs) {
    if (a.data_type !== 'select') continue;
    try {
      const full = (await api('GET', '/api/attributes/' + a.attribute_id)).data;
      const sel = $('[name="attr_' + a.attribute_id + '"]');
      if (sel && full.options) {
        const val = attrVals[a.attribute_id] ?? '';
        sel.outerHTML = `<select name="attr_${a.attribute_id}"><option value="">—</option>
          ${full.options.map(o => `<option value="${o.id}" ${o.id==val?'selected':''}>${esc(o.value)}</option>`).join('')}</select>`;
      }
    } catch {}
  }

  $('#btn-cancel').onclick = () => { state.editing = null; state.resolve = false; render(); };
  if ($('#btn-refresh-stock')) {
    $('#btn-refresh-stock').onclick = async () => {
      try { await api('POST', '/api/products/' + id + '/refresh-stock'); render(); } catch (e) { alert(e.message); }
    };
  }
  if ($('#btn-resolve')) {
    const doResolve = async (body) => {
      try { await api('POST', '/api/products/' + id + '/sync/resolve', body); state.resolve = false; state.editing = null; render(); }
      catch (e) { alert(e.message); }
    };
    $('#btn-resolve').onclick = () => {
      const choices = {};
      $$('[name^="choice_"]').forEach(r => { if (r.checked) choices[r.name.replace('choice_','')] = r.value; });
      doResolve({ choices });
    };
    $('#btn-keep-pims').onclick = () => doResolve({ keep_all: 'pims' });
    $('#btn-keep-wc').onclick = () => doResolve({ keep_all: 'woocommerce' });
    $('#btn-cancel-resolve').onclick = () => { state.resolve = false; render(); };
  }

  $('#prod-form').onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = {
      sku: fd.get('sku'), name: fd.get('name'), description: fd.get('description'),
      category_id: +fd.get('category_id'), brand: fd.get('brand') || null,
      price: fd.get('price'), stock: +fd.get('stock'), status: fd.get('status'),
      attributes: [],
    };
    if (fd.get('version')) body.version = +fd.get('version');
    catAttrs.forEach(a => {
      let v = fd.get('attr_' + a.attribute_id);
      if (v === null || v === '') return;
      if (a.data_type === 'boolean') v = v === '1';
      else if (a.data_type === 'number' || a.data_type === 'unit') v = +v;
      else if (a.data_type === 'select') v = +v;
      body.attributes.push({ attribute_id: a.attribute_id, value: v });
    });
    try {
      if (product) await api('PUT', '/api/products/' + product.id, body);
      else await api('POST', '/api/products', body);
      state.editing = null; render();
    } catch (err) {
      const el = $('#form-err');
      el.textContent = err.message + (err.fields ? ' — ' + JSON.stringify(err.fields) : '');
      el.classList.remove('hidden');
    }
  };
}

async function renderCategories(app) {
  const r = await api('GET', '/api/categories');
  state.categories = r.data || [];
  const attrs = (await api('GET', '/api/attributes')).data || [];
  state.attributes = attrs;

  app.innerHTML = `
    <div class="toolbar">
      <button class="btn primary" id="btn-new-cat">+ New category</button>
    </div>
    <div class="card" style="padding:0;overflow:auto">
      <table>
        <thead><tr><th>Name</th><th>Description</th><th>WC ID</th><th></th></tr></thead>
        <tbody>
          ${state.categories.map(c => `
            <tr data-id="${c.id}">
              <td>${esc(c.name)}</td>
              <td class="muted">${esc(c.description||'')}</td>
              <td class="muted">${c.woocommerce_id||'—'}</td>
              <td class="actions">
                <button class="btn sm" data-act="edit">Edit</button>
                <button class="btn sm" data-act="attrs">Attributes</button>
                <button class="btn sm danger" data-act="del">Del</button>
              </td>
            </tr>`).join('') || '<tr><td colspan="4" class="empty">No categories</td></tr>'}
        </tbody>
      </table>
    </div>
    <div id="cat-panel"></div>`;

  $('#btn-new-cat').onclick = () => {
    const name = prompt('Category name:');
    if (!name) return;
    api('POST', '/api/categories', { name }).then(render).catch(e => alert(e.message));
  };
  app.querySelector('tbody').onclick = async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const id = +btn.closest('tr').dataset.id;
    if (btn.dataset.act === 'del') {
      if (!confirm('Delete category?')) return;
      try { await api('DELETE', '/api/categories/' + id); render(); } catch (err) { alert(err.message); }
    } else if (btn.dataset.act === 'edit') {
      const c = state.categories.find(x => x.id === id);
      const name = prompt('Name:', c.name);
      if (!name) return;
      try { await api('PUT', '/api/categories/' + id, { name, description: c.description }); render(); } catch (err) { alert(err.message); }
    } else if (btn.dataset.act === 'attrs') {
      const detail = (await api('GET', '/api/categories/' + id)).data;
      const assigned = new Map((detail.attributes||[]).map(a => [a.attribute_id, a]));
      $('#cat-panel').innerHTML = `<div class="card"><h2>Attributes for ${esc(detail.name)}</h2>
        ${attrs.map(a => {
          const cur = assigned.get(a.id);
          return `<label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.4rem">
            <input type="checkbox" data-aid="${a.id}" ${cur?'checked':''}>
            ${esc(a.name)} <span class="muted">(${esc(a.data_type)})</span>
            <label style="margin:0 0 0 1rem;display:inline;color:var(--muted)"><input type="checkbox" data-req="${a.id}" ${cur?.required==1?'checked':''}> required</label>
          </label>`;
        }).join('') || '<p class="muted">No attributes defined yet.</p>'}
        <button class="btn primary" id="btn-save-cat-attrs" style="margin-top:0.75rem">Save</button>
      </div>`;
      $('#btn-save-cat-attrs').onclick = async () => {
        const assignments = [];
        $$('[data-aid]').forEach(cb => {
          if (cb.checked) {
            const aid = +cb.dataset.aid;
            const req = $('[data-req="' + aid + '"]')?.checked;
            assignments.push({ attribute_id: aid, required: !!req });
          }
        });
        try { await api('PUT', '/api/categories/' + id + '/attributes', { attributes: assignments }); alert('Saved'); render(); }
        catch (err) { alert(err.message); }
      };
    }
  };
}

async function renderAttributes(app) {
  const r = await api('GET', '/api/attributes');
  state.attributes = r.data || [];

  app.innerHTML = `
    <div class="toolbar">
      <button class="btn primary" id="btn-new-attr">+ New attribute</button>
    </div>
    <div class="card" style="padding:0;overflow:auto">
      <table>
        <thead><tr><th>Name</th><th>Type</th><th>Unit</th><th>Options</th><th>WC ID</th><th></th></tr></thead>
        <tbody>
          ${state.attributes.map(a => `
            <tr data-id="${a.id}">
              <td>${esc(a.name)}</td>
              <td><code>${esc(a.data_type)}</code></td>
              <td class="muted">${esc(a.unit||'—')}</td>
              <td class="muted">${a.data_type==='select' ? (a.options||[]).map(o=>esc(o.value)).join(', ') : '—'}</td>
              <td class="muted">${a.woocommerce_id||'—'}</td>
              <td class="actions">
                ${a.data_type==='select' ? '<button class="btn sm" data-act="opt">+ Option</button>' : ''}
                <button class="btn sm danger" data-act="del">Del</button>
              </td>
            </tr>`).join('') || '<tr><td colspan="6" class="empty">No attributes</td></tr>'}
        </tbody>
      </table>
    </div>`;

  $('#btn-new-attr').onclick = async () => {
    const name = prompt('Attribute name:');
    if (!name) return;
    const type = prompt('Type (text, number, boolean, select, unit):', 'text');
    if (!type) return;
    const body = { name, data_type: type };
    if (type === 'unit') body.unit = prompt('Unit (e.g. GB):', 'GB') || 'unit';
    if (type === 'select') {
      const opts = prompt('Options (comma-separated):', 'Red,Blue,Green');
      body.options = (opts||'').split(',').map(s => s.trim()).filter(Boolean);
    }
    try { await api('POST', '/api/attributes', body); render(); } catch (e) { alert(e.message); }
  };
  app.querySelector('tbody').onclick = async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const id = +btn.closest('tr').dataset.id;
    if (btn.dataset.act === 'del') {
      if (!confirm('Delete attribute?')) return;
      try { await api('DELETE', '/api/attributes/' + id); render(); } catch (err) { alert(err.message); }
    } else if (btn.dataset.act === 'opt') {
      const val = prompt('New option value:');
      if (!val) return;
      try { await api('POST', '/api/attributes/' + id + '/options', { value: val }); render(); } catch (err) { alert(err.message); }
    }
  };
}

async function renderLogs(app) {
  const r = await api('GET', '/api/sync/logs?limit=50');
  const logs = r.data || [];
  app.innerHTML = `
    <div class="card" style="padding:0;overflow:auto">
      <table>
        <thead><tr><th>Time</th><th>Product</th><th>Direction</th><th>Outcome</th><th>HTTP</th><th>Error</th></tr></thead>
        <tbody>
          ${logs.map(l => `
            <tr>
              <td class="muted">${esc(l.created_at)}</td>
              <td>${l.product_id ?? '—'}</td>
              <td>${esc(l.direction)}</td>
              <td><span class="badge ${esc(l.outcome)}">${esc(l.outcome)}</span></td>
              <td class="muted">${l.http_status ?? '—'}</td>
              <td class="muted" style="max-width:280px;overflow:hidden;text-overflow:ellipsis">${esc(l.error||'')}</td>
            </tr>`).join('') || '<tr><td colspan="6" class="empty">No logs yet</td></tr>'}
        </tbody>
      </table>
    </div>`;
}

init();
