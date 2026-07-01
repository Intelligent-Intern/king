const messagesEl = document.getElementById('messages');
const formEl = document.getElementById('chatForm');
const inputEl = document.getElementById('promptInput');
const sendButton = document.getElementById('sendButton');
const deleteThreadButton = document.getElementById('deleteThreadButton');
const newThreadButton = document.getElementById('newThreadButton');
const threadListEl = document.getElementById('threadList');
const statusDot = document.getElementById('statusDot');
const statusText = document.getElementById('statusText');

let history = [];
let activeController = null;
let threads = [];
let currentThreadId = null;

function escapeHtml(value) {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function safeLinkTarget(href) {
  const trimmed = href.trim();
  if (/^(https?:|mailto:|\/|#)/i.test(trimmed)) {
    return trimmed;
  }
  return '#';
}

function highlightCode(source, language = '') {
  const lang = language.toLowerCase();
  let html = escapeHtml(source);

  if (['json', 'iibin'].includes(lang)) {
    html = html
      .replace(/(&quot;[^&]*?&quot;)(\s*:)/g, '<span class="tok-key">$1</span>$2')
      .replace(/:\s*(&quot;.*?&quot;)/g, ': <span class="tok-string">$1</span>')
      .replace(/\b(true|false|null)\b/g, '<span class="tok-const">$1</span>')
      .replace(/\b-?\d+(?:\.\d+)?\b/g, '<span class="tok-number">$&</span>');
    return html;
  }

  if (['php', 'js', 'javascript', 'ts', 'typescript'].includes(lang)) {
    html = html
      .replace(/(\/\/.*)$/gm, '<span class="tok-comment">$1</span>')
      .replace(/(&quot;.*?&quot;|&#039;.*?&#039;|`.*?`)/g, '<span class="tok-string">$1</span>')
      .replace(/\b(function|return|class|public|private|protected|static|const|let|var|if|else|foreach|for|while|true|false|null|new|try|catch|throw|bool|int|string|array|void)\b/g, '<span class="tok-keyword">$1</span>')
      .replace(/\b-?\d+(?:\.\d+)?\b/g, '<span class="tok-number">$&</span>');
    return html;
  }

  if (['html', 'xml', 'svg'].includes(lang)) {
    html = html
      .replace(/(&lt;\/?)([A-Za-z0-9:_-]+)/g, '$1<span class="tok-keyword">$2</span>')
      .replace(/([A-Za-z0-9:_-]+)=(&quot;.*?&quot;)/g, '<span class="tok-key">$1</span>=<span class="tok-string">$2</span>');
    return html;
  }

  if (['css', 'scss'].includes(lang)) {
    html = html
      .replace(/([.#]?[A-Za-z0-9_-]+)(\s*\{)/g, '<span class="tok-keyword">$1</span>$2')
      .replace(/([A-Za-z-]+)(\s*:)/g, '<span class="tok-key">$1</span>$2')
      .replace(/(#(?:[0-9a-f]{3}){1,2}\b|\b\d+(?:px|rem|em|%)?\b)/gi, '<span class="tok-number">$1</span>');
    return html;
  }

  if (['bash', 'sh', 'shell'].includes(lang)) {
    html = html
      .replace(/(^|\s)(sudo|docker|php|curl|git|make|npm|composer|export|cd|cp|mv|rm)(?=\s|$)/g, '$1<span class="tok-keyword">$2</span>')
      .replace(/(--?[A-Za-z0-9_-]+)/g, '<span class="tok-key">$1</span>')
      .replace(/(&quot;.*?&quot;|&#039;.*?&#039;)/g, '<span class="tok-string">$1</span>');
    return html;
  }

  return html;
}

function createCodeBox(code, language = 'text') {
  const box = document.createElement('figure');
  box.className = 'codebox';
  const header = document.createElement('figcaption');
  header.textContent = language || 'text';
  const pre = document.createElement('pre');
  const codeEl = document.createElement('code');
  codeEl.className = `language-${language || 'text'}`;
  codeEl.innerHTML = highlightCode(code, language);
  pre.append(codeEl);
  box.append(header, pre);
  return box;
}

function renderInlineMarkdown(text) {
  const template = document.createElement('template');
  let html = escapeHtml(text);
  const inlineCodes = [];
  html = html.replace(/`([^`]+)`/g, (_, code) => {
    const index = inlineCodes.push(`<code>${code}</code>`) - 1;
    return `\u0000INLINE${index}\u0000`;
  });
  html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_, label, href) => {
    const safeHref = escapeHtml(safeLinkTarget(href));
    return `<a href="${safeHref}" target="_blank" rel="noreferrer">${label}</a>`;
  });
  html = html
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/\b_([^_]+)_\b/g, '<em>$1</em>');
  html = html.replace(/\u0000INLINE(\d+)\u0000/g, (_, index) => inlineCodes[Number(index)] || '');
  template.innerHTML = html;
  return template.content;
}

function appendParagraph(container, lines) {
  const text = lines.join(' ').trim();
  if (text === '') {
    return;
  }
  const paragraph = document.createElement('p');
  paragraph.append(renderInlineMarkdown(text));
  container.append(paragraph);
}

function renderMarkdownDocument(source) {
  const root = document.createElement('div');
  root.className = 'rendered-markdown';
  const lines = source.replace(/\r\n/g, '\n').split('\n');
  let paragraph = [];
  let list = null;

  const flushList = () => {
    if (list !== null) {
      root.append(list);
      list = null;
    }
  };
  const flushParagraph = () => {
    appendParagraph(root, paragraph);
    paragraph = [];
  };

  for (let index = 0; index < lines.length; index++) {
    const line = lines[index];
    const fence = line.match(/^(```|~~~)([A-Za-z0-9_.+-]*)\s*$/);
    if (fence !== null) {
      flushParagraph();
      flushList();
      const marker = fence[1];
      const language = fence[2] || 'text';
      const codeLines = [];
      index++;
      while (index < lines.length && lines[index].trim() !== marker) {
        codeLines.push(lines[index]);
        index++;
      }
      root.append(createCodeBox(codeLines.join('\n'), language));
      continue;
    }

    const heading = line.match(/^(#{1,4})\s+(.+)$/);
    if (heading !== null) {
      flushParagraph();
      flushList();
      const level = Math.min(4, heading[1].length + 1);
      const node = document.createElement(`h${level}`);
      node.append(renderInlineMarkdown(heading[2].trim()));
      root.append(node);
      continue;
    }

    const item = line.match(/^\s*[-*]\s+(.+)$/);
    if (item !== null) {
      flushParagraph();
      if (list === null) {
        list = document.createElement('ul');
      }
      const li = document.createElement('li');
      li.append(renderInlineMarkdown(item[1].trim()));
      list.append(li);
      continue;
    }

    const quote = line.match(/^>\s?(.+)$/);
    if (quote !== null) {
      flushParagraph();
      flushList();
      const blockquote = document.createElement('blockquote');
      blockquote.append(renderInlineMarkdown(quote[1].trim()));
      root.append(blockquote);
      continue;
    }

    if (line.trim() === '') {
      flushParagraph();
      flushList();
      continue;
    }

    paragraph.push(line);
  }

  flushParagraph();
  flushList();
  return root;
}

function unwrapCompleteFence(raw, marker) {
  const escaped = marker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = raw.match(new RegExp(`^${escaped}([A-Za-z0-9_.+-]*)\\s*\\n([\\s\\S]*?)\\n${escaped}\\s*$`));
  if (match !== null) {
    return { language: match[1] || 'text', body: match[2], complete: true };
  }
  const partial = raw.match(new RegExp(`^${escaped}([A-Za-z0-9_.+-]*)\\s*\\n([\\s\\S]*)$`));
  if (partial !== null) {
    return { language: partial[1] || 'text', body: partial[2], complete: false };
  }
  return null;
}

function renderContent(raw, role) {
  const content = raw.trim();
  if (role !== 'assistant' || content === '') {
    const plain = document.createElement('span');
    plain.textContent = raw;
    return plain;
  }

  const tildeFence = unwrapCompleteFence(content, '~~~');
  if (tildeFence !== null) {
    return createCodeBox(tildeFence.body, tildeFence.language || 'markdown');
  }

  const backtickFence = unwrapCompleteFence(content, '```');
  if (backtickFence !== null && backtickFence.language.toLowerCase() === 'markdown') {
    return renderMarkdownDocument(backtickFence.body);
  }
  if (backtickFence !== null) {
    return createCodeBox(backtickFence.body, backtickFence.language);
  }

  return renderMarkdownDocument(raw);
}

function setMessageContent(body, content) {
  const role = body.closest('.message')?.classList.contains('assistant') ? 'assistant' : 'user';
  body.dataset.rawContent = content;
  body.replaceChildren(renderContent(content, role));
}

function setStatus(state, text) {
  statusDot.classList.toggle('ok', state === 'ok');
  statusDot.classList.toggle('error', state === 'error');
  statusText.textContent = text;
}

function clearMessages() {
  messagesEl.textContent = '';
}

function addMessage(role, content, extraClass = '') {
  const node = document.createElement('article');
  node.className = `message ${role} ${extraClass}`.trim();
  const label = document.createElement('span');
  label.className = 'role';
  label.textContent = role;
  const body = document.createElement('div');
  body.className = 'content';
  node.append(label, body);
  messagesEl.appendChild(node);
  setMessageContent(body, content);
  messagesEl.scrollTop = messagesEl.scrollHeight;
  return body;
}

function appendAssistantDelta(body, delta) {
  setMessageContent(body, (body.dataset.rawContent || '') + delta);
  messagesEl.scrollTop = messagesEl.scrollHeight;
}

async function loadHealth() {
  try {
    const response = await fetch('/api/health', { cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      setStatus('error', 'Inference offline');
      return;
    }

    setStatus('ok', 'Ready');
  } catch (error) {
    setStatus('error', 'Health check failed');
  }
}

async function apiJson(url, options = {}) {
  const response = await fetch(url, {
    cache: 'no-store',
    ...options,
    headers: {
      ...(options.body ? { 'content-type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || data?.ok === false) {
    throw new Error(data?.error?.message || `HTTP ${response.status}`);
  }
  return data;
}

function threadPreviewTitle(thread) {
  const title = typeof thread.title === 'string' && thread.title.trim() !== '' ? thread.title.trim() : 'New chat';
  return title.length > 64 ? `${title.slice(0, 61)}...` : title;
}

function renderThreads() {
  threadListEl.textContent = '';
  if (threads.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'thread-empty';
    empty.textContent = 'No threads yet';
    threadListEl.append(empty);
    return;
  }

  for (const thread of threads) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `thread-item${thread.id === currentThreadId ? ' active' : ''}`;
    button.dataset.threadId = thread.id;

    const title = document.createElement('span');
    title.className = 'thread-title';
    title.textContent = threadPreviewTitle(thread);

    const meta = document.createElement('span');
    meta.className = 'thread-meta';
    const count = Number(thread.message_count || 0);
    meta.textContent = `${count} message${count === 1 ? '' : 's'}`;

    button.append(title, meta);
    button.addEventListener('click', () => selectThread(thread.id));
    threadListEl.append(button);
  }
}

async function refreshThreads() {
  const data = await apiJson('/api/threads');
  threads = Array.isArray(data.threads) ? data.threads : [];
  renderThreads();
}

function renderLoadedMessages(messages) {
  clearMessages();
  history = [];
  for (const message of messages) {
    if (!['user', 'assistant'].includes(message.role) || typeof message.content !== 'string') {
      continue;
    }
    history.push({ role: message.role, content: message.content });
    addMessage(message.role, message.content);
  }
  if (history.length === 0) {
    addMessage('assistant', 'Thread ready.');
  }
}

async function selectThread(threadId) {
  currentThreadId = threadId;
  localStorage.setItem('king-chat-thread-id', threadId);
  renderThreads();
  const data = await apiJson(`/api/threads/${encodeURIComponent(threadId)}`);
  currentThreadId = data.thread.id;
  renderLoadedMessages(Array.isArray(data.messages) ? data.messages : []);
  renderThreads();
  inputEl.focus();
}

async function createThread() {
  const data = await apiJson('/api/threads', {
    method: 'POST',
    body: JSON.stringify({ title: 'New chat' }),
  });
  await refreshThreads();
  await selectThread(data.thread.id);
}

async function deleteCurrentThread() {
  if (!currentThreadId) {
    return;
  }
  const deleting = currentThreadId;
  await apiJson(`/api/threads/${encodeURIComponent(deleting)}`, { method: 'DELETE' });
  currentThreadId = null;
  history = [];
  clearMessages();
  await refreshThreads();
  if (threads.length === 0) {
    await createThread();
  } else {
    await selectThread(threads[0].id);
  }
}

async function loadThreads() {
  await refreshThreads();
  if (threads.length === 0) {
    await createThread();
    return;
  }
  const stored = localStorage.getItem('king-chat-thread-id');
  const selected = threads.find((thread) => thread.id === stored)?.id || threads[0].id;
  await selectThread(selected);
}

function parseSseBlock(block) {
  let event = 'message';
  let data = '';
  for (const rawLine of block.split('\n')) {
    const line = rawLine.trimEnd();
    if (line.startsWith('event:')) {
      event = line.slice(6).trim();
    } else if (line.startsWith('data:')) {
      data += line.slice(5).trim();
    }
  }
  if (data === '') {
    return null;
  }
  try {
    return { event, data: JSON.parse(data) };
  } catch {
    return { event, data: { raw: data } };
  }
}

async function sendMessage(prompt) {
  if (activeController) {
    activeController.abort();
  }
  if (!currentThreadId) {
    await createThread();
  }

  const userMessage = { role: 'user', content: prompt };
  if (history.length === 0) {
    clearMessages();
  }
  history.push(userMessage);
  addMessage('user', prompt);
  const assistantBody = addMessage('assistant', '');

  sendButton.disabled = true;
  inputEl.disabled = true;
  activeController = new AbortController();

  try {
    const response = await fetch('/api/chat', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ thread_id: currentThreadId, message: prompt }),
      signal: activeController.signal,
    });

    if (!response.ok || !response.body) {
      const text = await response.text();
      throw new Error(text || `HTTP ${response.status}`);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let assistantText = '';

    while (true) {
      const { value, done } = await reader.read();
      if (done) {
        break;
      }
      buffer += decoder.decode(value, { stream: true });

      let boundary = buffer.indexOf('\n\n');
      while (boundary !== -1) {
        const block = buffer.slice(0, boundary);
        buffer = buffer.slice(boundary + 2);
        const parsed = parseSseBlock(block);
        if (parsed?.event === 'delta' && typeof parsed.data.content === 'string') {
          assistantText += parsed.data.content;
          appendAssistantDelta(assistantBody, parsed.data.content);
        } else if (parsed?.event === 'thread' && parsed.data.thread?.id) {
          currentThreadId = parsed.data.thread.id;
          localStorage.setItem('king-chat-thread-id', currentThreadId);
          const existing = threads.findIndex((thread) => thread.id === currentThreadId);
          if (existing >= 0) {
            threads[existing] = parsed.data.thread;
          } else {
            threads.unshift(parsed.data.thread);
          }
          renderThreads();
        } else if (parsed?.event === 'error') {
          throw new Error(parsed.data.message || 'Inference error');
        }
        boundary = buffer.indexOf('\n\n');
      }
    }

    if (assistantText.trim() === '') {
      assistantBody.parentElement.classList.add('error');
      setMessageContent(assistantBody, 'No assistant content returned.');
    } else {
      history.push({ role: 'assistant', content: assistantText });
      await refreshThreads();
    }
  } catch (error) {
    assistantBody.parentElement.classList.add('error');
    setMessageContent(assistantBody, error instanceof Error ? error.message : 'Request failed.');
  } finally {
    sendButton.disabled = false;
    inputEl.disabled = false;
    inputEl.focus();
    activeController = null;
  }
}

formEl.addEventListener('submit', (event) => {
  event.preventDefault();
  const prompt = inputEl.value.trim();
  if (prompt === '') {
    return;
  }
  inputEl.value = '';
  sendMessage(prompt);
});

newThreadButton.addEventListener('click', () => {
  createThread().catch((error) => {
    setStatus('error', error instanceof Error ? error.message : 'Could not create thread');
  });
});

deleteThreadButton.addEventListener('click', () => {
  deleteCurrentThread().catch((error) => {
    setStatus('error', error instanceof Error ? error.message : 'Could not delete thread');
  });
});

Promise.all([loadHealth(), loadThreads()]).catch((error) => {
  setStatus('error', error instanceof Error ? error.message : 'Startup failed');
});
