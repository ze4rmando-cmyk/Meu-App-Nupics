<?php
/**
 * NUPICS — Componente de Upload de Imagem
 * 
 * Inclua onde precisar de upload:
 *   <?php include '../includes/upload_component.php'; ?>
 * 
 * Depois use o helper JS:
 *   uploadImagem(inputEl, contexto, extraData, onSuccess)
 */
?>
<style>
/* ── Upload component styles ──────────────────────────────────────── */
.upload-area {
  border: 2px dashed var(--color-outline-variant, #d0c2d3);
  border-radius: 16px;
  padding: 20px;
  text-align: center;
  cursor: pointer;
  transition: all .2s;
  background: transparent;
  position: relative;
  overflow: hidden;
}
.upload-area:hover, .upload-area.drag-over {
  border-color: #4e0078;
  background: rgba(78,0,120,.04);
}
.upload-area input[type=file] {
  position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.upload-preview {
  width: 100%; border-radius: 12px; object-fit: cover;
  display: none; margin-bottom: 8px;
}
.upload-preview.visible { display: block; }

/* Avatar circular */
.avatar-upload { position: relative; display: inline-block; cursor: pointer; }
.avatar-upload img { border-radius: 9999px; object-fit: cover; display: block; }
.avatar-upload .avatar-overlay {
  position: absolute; inset: 0; border-radius: 9999px;
  background: rgba(0,0,0,.45); opacity: 0; transition: .2s;
  display: flex; align-items: center; justify-content: center;
  color: white; font-size: 11px; font-weight: 700; flex-direction: column; gap: 3px;
}
.avatar-upload:hover .avatar-overlay { opacity: 1; }
.avatar-upload input[type=file] {
  position: absolute; inset: 0; opacity: 0; cursor: pointer; border-radius: 9999px;
}

/* Barra de progresso */
.upload-progress {
  height: 4px; border-radius: 99px; background: #e8dce9;
  overflow: hidden; margin-top: 8px; display: none;
}
.upload-progress.active { display: block; }
.upload-progress-bar {
  height: 100%; background: linear-gradient(90deg,#4e0078,#b7004d);
  border-radius: 99px; width: 0%; transition: width .3s;
  animation: shimmer 1.5s infinite;
}
@keyframes shimmer {
  0%,100%{opacity:1} 50%{opacity:.6}
}
</style>

<script>
/**
 * uploadImagem(inputElement, contexto, extraData, onSuccess)
 * 
 * @param {HTMLInputElement} input    - o <input type="file">
 * @param {string}           contexto - 'perfil' | 'aviso' | 'visita' | 'logo'
 * @param {object}           extra    - ex: {aviso_id: 3} ou {visita_id: 7}
 * @param {function}         onSuccess(url, path) - callback com a URL da imagem
 */
async function uploadImagem(input, contexto, extra={}, onSuccess=null) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];

  // Valida tamanho no cliente (2MB)
  if (file.size > 2 * 1024 * 1024) {
    if (typeof toast === 'function') toast('Imagem muito grande. Máximo 2MB.','error','text-red-500');
    else alert('Imagem muito grande. Máximo 2MB.');
    input.value = '';
    return;
  }

  // Mostra preview imediato
  const previewEl = document.getElementById('upload-preview-' + contexto + (extra.aviso_id||extra.visita_id||''));
  if (previewEl) {
    const reader = new FileReader();
    reader.onload = e => { previewEl.src = e.target.result; previewEl.classList.add('visible'); };
    reader.readAsDataURL(file);
  }

  // Monta FormData
  const fd = new FormData();
  fd.append('imagem', file);
  fd.append('contexto', contexto);
  for (const [k,v] of Object.entries(extra)) fd.append(k, v);

  // Barra de progresso
  const bar = document.getElementById('upload-bar-' + contexto + (extra.aviso_id||extra.visita_id||''));
  if (bar) bar.classList.add('active');
  const barFill = bar?.querySelector('.upload-progress-bar');

  try {
    // XMLHttpRequest para ter progresso real
    const result = await new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', '../api/upload.php');
      xhr.upload.onprogress = e => {
        if (e.lengthComputable && barFill)
          barFill.style.width = Math.round((e.loaded/e.total)*100) + '%';
      };
      xhr.onload = () => {
        try { resolve(JSON.parse(xhr.responseText)); }
        catch { reject(new Error('Resposta inválida do servidor.')); }
      };
      xhr.onerror = () => reject(new Error('Erro de rede.'));
      xhr.send(fd);
    });

    if (bar) { barFill.style.width='100%'; setTimeout(()=>bar.classList.remove('active'),800); }

    if (result.ok) {
      if (typeof toast === 'function') toast('Imagem enviada!','check_circle','text-emerald-600');
      if (typeof onSuccess === 'function') onSuccess(result.url, result.path);
    } else {
      if (typeof toast === 'function') toast(result.msg||'Erro no upload.','error','text-red-500');
      else alert(result.msg||'Erro no upload.');
      if (previewEl) previewEl.classList.remove('visible');
    }
  } catch(e) {
    if (bar) bar.classList.remove('active');
    if (typeof toast === 'function') toast('Erro de conexão: ' + e.message,'error','text-red-500');
    else alert('Erro: ' + e.message);
    if (previewEl) previewEl.classList.remove('visible');
  }
  input.value = ''; // reset input
}

// Drag & drop para .upload-area
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.upload-area').forEach(area => {
    area.addEventListener('dragover', e => { e.preventDefault(); area.classList.add('drag-over'); });
    area.addEventListener('dragleave', () => area.classList.remove('drag-over'));
    area.addEventListener('drop', e => {
      e.preventDefault(); area.classList.remove('drag-over');
      const input = area.querySelector('input[type=file]');
      if (input && e.dataTransfer.files.length) {
        const dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        input.files = dt.files;
        input.dispatchEvent(new Event('change'));
      }
    });
  });
});
</script>