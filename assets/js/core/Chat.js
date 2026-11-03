import { CopilotManager } from "./CopilotManager.js";

/**
 * Chat (core)
 * Ponto central da interface de chat.
 *
 * Responsabilidades:
 * - Renderizar mensagens (usuário, copilot, loading, error, success)
 * - Expor eventos de envio (`fc/chat/submit`) e ciclo de vida.
 *
 * NÃO conhece menu de skills, dropdowns, validações ou regras de negócio.
 */
export const Chat = {
  root: null,
  container: null,
  input: null,
  button: null,

  _templates: {},

  // ─── Inicialização ────────────────────────────────────────

  attach(root) {
    this.root = root;

    root.classList.remove("chating");
    this._setup();
    this._emit("fc/chat/ready");
  },

  // ─── API do Chat para outros módulos ──────────────────────

  setSkillContext(skill) {
    // Apenas muda visual do input para adequar ao contexto da skill
    this.input.placeholder =
      skill.inputPlaceholder ?? "Clique ou digite para ver as opções";
  },

  toggleSubmitButton(isDisabled) {
    this.button?.classList.toggle("desabilitado", isDisabled);

    if (isDisabled) {
      this.button?.setAttribute("disabled", "disabled");
      this.button?.setAttribute("tabindex", "-1");
      this.button?.classList.remove("ativo");
    } else {
      this.button?.removeAttribute("disabled");
      this.button?.removeAttribute("tabindex");
      this.button?.classList.add("ativo");
    }
  },

  // ─── Mensagens ────────────────────────────────────────────

  sendUserMessage(text) {
    if (!text) return;
    this.root.classList.add("chating");
    this._appendTemplate(this._templates.user, text);
    this.input.value = "";
  },

  sendCopilotMissingToolOrSkillMessage() {
    const loading = this.sendLoadingMessage();
    setTimeout(() => {
      loading.remove();
      this.sendCopilotMessage(
        "Desculpe, não consegui entender o que você precisa ou não possuo a skill necessária para realizar a operação.",
        "error",
      );
    }, 1500);
  },

  sendCopilotMessage(
    text,
    status = "normal",
    actions = [],
  ) {
    this.root.classList.add("chating");

    const templateMap = {
      error: this._templates.error,
      success: this._templates.success,
      normal: this._templates.copilot,
    };

    // Check if we should update the last message (in-place progress)
    const lastMsgElem = this.container.lastElementChild;
    const isUpdate = lastMsgElem && 
                     lastMsgElem.querySelector(".fs-chat__content") && 
                     (
                       text.startsWith("Baixando arquivo do plugin") && 
                       lastMsgElem.querySelector(".fs-chat__content").innerText.startsWith("Baixando arquivo do plugin")
                     );

    let message;
    if (isUpdate) {
      message = lastMsgElem;
      const contentElem = message.querySelector(".fs-chat__content");
      if (contentElem) {
        contentElem.innerHTML = text;
      }
    } else {
      message = this._appendTemplate(
        templateMap[status] ?? this._templates.copilot,
        text,
      );
    }

    // Por padrão, insere a ação "Reiniciar chat" se não estiver presente
    const hasRestart = actions.some((a) => a.action === "restart-chat");
    if (!hasRestart) {
      actions.push({
        label: "Reiniciar chat",
        action: "restart-chat",
      });
    }

    if (message) {
      this._renderActions(message, actions);
    }
  },

  sendProgressMessage(id, label, percent) {
    if (!this.root || !this.container) return;

    this.root.classList.add("chating");

    const element = this.container.querySelector(`.fc-progress-message-${id}`);

    if (element) {
      const labelElem = element.querySelector(".fc-progress-label");
      const valueElem = element.querySelector(".fc-progress-value");
      const fillElem = element.querySelector(".fc-progress-bar-fill");

      if (labelElem) labelElem.innerText = label;
      if (valueElem) valueElem.innerText = `${percent}%`;
      if (fillElem) fillElem.style.width = `${percent}%`;
    } else {
      const template = this._templates.copilot;
      if (!template || !this.container) return;

      const clone = template.content.cloneNode(true);
      const msgOuter = clone.querySelector(".fs-chat__msg");
      if (msgOuter) {
        msgOuter.classList.add(`fc-progress-message-${id}`);
      }

      const progressHtml = `
        <div class="fc-progress-container">
          <div class="fc-progress-header">
            <span class="fc-progress-label">${label}</span>
            <span class="fc-progress-value">${percent}%</span>
          </div>
          <div class="fc-progress-bar-wrapper">
            <div class="fc-progress-bar-fill" style="width: ${percent}%"></div>
          </div>
        </div>
      `;

      clone.querySelector(".fs-chat__content").innerHTML = progressHtml;
      this.container.appendChild(clone);
      this._scrollToBottom();
    }
  },

  sendLoadingMessage() {
    this.container.insertAdjacentHTML(
      "beforeend",
      this._templates.loading.innerHTML,
    );
    this._scrollToBottom();
    return this.container.lastElementChild;
  },

  sendLicensingCard(id, pluginName, pluginIconUrl, isAddon = false, addonName = "") {
    if (!this.root || !this.container) return;

    this.root.classList.add("chating");

    const template = this._templates.copilot;
    if (!template || !this.container) return;

    const clone = template.content.cloneNode(true);
    const textContent = clone.querySelector(".fs-chat__content");

    const cardHtml = isAddon ? `
      <div class="fs-licensing-card-info mb-3">
        Estamos instalando o addon <strong>${addonName}</strong> do plugin <strong>${pluginName}</strong>, não feche ou recarregue a página durante este processo.
      </div>
      <div class="fs-licensing-card" id="licensing-card-${id}">
        <div class="fs-licensing-card__steps d-flex flex-column gap-3">
          <div class="fs-licensing-step d-flex align-items-start gap-2 status-pending" data-step="1">
            <span class="fs-licensing-step__badge"></span>
            <div class="fs-licensing-step__content">
              <span class="fs-licensing-step__label">Baixar e instalar plugin</span>
              <div class="fs-licensing-step__detail mt-1 text-muted small" style="display: none;"></div>
            </div>
          </div>
          <div class="fs-licensing-step d-flex align-items-start gap-2 status-pending" data-step="2">
            <span class="fs-licensing-step__badge"></span>
            <div class="fs-licensing-step__content">
              <span class="fs-licensing-step__label">Ativar no WordPress</span>
              <div class="fs-licensing-step__detail mt-1 text-muted small" style="display: none;"></div>
            </div>
          </div>
        </div>
      </div>
    ` : `
      <div class="fs-licensing-card-info mb-3">
        Estamos fazendo a ativação do seu plugin <strong>${pluginName}</strong>, não feche ou recarregue a página durante este processo.
      </div>
      <div class="fs-licensing-card" id="licensing-card-${id}">
        <div class="fs-licensing-card__steps d-flex flex-column gap-3">
          <div class="fs-licensing-step d-flex align-items-start gap-2 status-pending" data-step="1">
            <span class="fs-licensing-step__badge"></span>
            <div class="fs-licensing-step__content">
              <span class="fs-licensing-step__label">Solicitar ativação na FULL</span>
              <div class="fs-licensing-step__detail mt-1 text-muted small" style="display: none;"></div>
            </div>
          </div>
          <div class="fs-licensing-step d-flex align-items-start gap-2 status-pending" data-step="2">
            <span class="fs-licensing-step__badge"></span>
            <div class="fs-licensing-step__content">
              <span class="fs-licensing-step__label">Baixar e instalar plugin</span>
              <div class="fs-licensing-step__detail mt-1 text-muted small" style="display: none;"></div>
            </div>
          </div>
          <div class="fs-licensing-step d-flex align-items-start gap-2 status-pending" data-step="3">
            <span class="fs-licensing-step__badge"></span>
            <div class="fs-licensing-step__content">
              <span class="fs-licensing-step__label">Ativar no WordPress</span>
              <div class="fs-licensing-step__detail mt-1 text-muted small" style="display: none;"></div>
            </div>
          </div>
          <div class="fs-licensing-step d-flex align-items-start gap-2 status-pending" data-step="4">
            <span class="fs-licensing-step__badge"></span>
            <div class="fs-licensing-step__content">
              <span class="fs-licensing-step__label">Ativar licença PRO</span>
              <div class="fs-licensing-step__detail mt-1 text-muted small" style="display: none;"></div>
            </div>
          </div>
        </div>
      </div>
    `;

    if (textContent) {
      textContent.innerHTML = cardHtml;
    }

    this.container.appendChild(clone);
    this._scrollToBottom();
  },

  updateLicensingCard(id, stepIndex, status, detailText = "", progressPercent = null, actions = [], customLabel = null) {
    const card = this.container.querySelector(`#licensing-card-${id}`);
    if (!card) return;

    const allSteps = card.querySelectorAll('.fs-licensing-step');
    allSteps.forEach((el) => {
      const idx = parseInt(el.dataset.step);
      if (idx < stepIndex) {
        el.classList.remove('status-pending', 'status-processing', 'status-failed');
        el.classList.add('status-completed');
        const dEl = el.querySelector('.fs-licensing-step__detail');
        if (dEl && dEl.innerText.trim() === '') {
          dEl.style.display = 'none';
        }
      } else if (idx > stepIndex) {
        el.classList.remove('status-processing', 'status-completed', 'status-failed');
        el.classList.add('status-pending');
        const dEl = el.querySelector('.fs-licensing-step__detail');
        if (dEl) {
          dEl.innerHTML = '';
          dEl.style.display = 'none';
        }
      }
    });

    const stepEl = card.querySelector(`.fs-licensing-step[data-step="${stepIndex}"]`);
    if (stepEl) {
      stepEl.classList.remove('status-pending', 'status-processing', 'status-completed', 'status-failed');
      stepEl.classList.add(`status-${status}`);

      if (customLabel) {
        const labelEl = stepEl.querySelector('.fs-licensing-step__label');
        if (labelEl) {
          labelEl.innerHTML = customLabel;
        }
      }

      const detailEl = stepEl.querySelector('.fs-licensing-step__detail');
      if (detailEl) {
        if (detailText) {
          detailEl.innerHTML = detailText;
          detailEl.style.display = 'block';
        } else {
          detailEl.innerHTML = '';
          detailEl.style.display = 'none';
        }

        if (progressPercent !== null) {
          const progressHtml = `
            <div class="fc-progress-container mt-2">
              <div class="fc-progress-bar-wrapper" style="height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                <div class="fc-progress-bar-fill" style="width: ${progressPercent}%; height: 100%; background: #f59e0b; transition: width 0.2s ease;"></div>
              </div>
            </div>
          `;
          detailEl.insertAdjacentHTML('beforeend', progressHtml);
          detailEl.style.display = 'block';
        }
      }
    }

    if (actions && actions.length) {
      const hasRestart = actions.some((a) => a.action === "restart-chat");
      if (!hasRestart) {
        actions.push({
          label: "Reiniciar chat",
          action: "restart-chat",
        });
      }

      const message = card.closest('.fs-chat__msg');
      if (message) {
        this._renderActions(message, actions);
      }
    }

    this._scrollToBottom();
  },

  // ─── Privado ──────────────────────────────────────────────

  _setup() {
    const sendBtn = this.root.querySelector("#btnEnviarCopilot");
    this.input = this.root.querySelector("#copilotTexto");

    if (!sendBtn || !this.input) return;

    this.container = this.root.querySelector("#fs-copilot-chat");

    this._templates = {
      user: this.root.querySelector("#chat-user"),
      copilot: this.root.querySelector("#chat-copilot"),
      loading: this.root.querySelector("#chat-loading"),
      error: this.root.querySelector("#chat-error"),
      success: this.root.querySelector("#chat-success"),
    };

    this.button = sendBtn.cloneNode(true);
    sendBtn.replaceWith(this.button);

    this._bindInputEvents();

    this.container.addEventListener("click", (e) => {
      const actionBtn = e.target.closest(".btn-chat-action");
      if (!actionBtn) return;

      const lastMessage = this.container.lastElementChild;
      if (lastMessage && lastMessage.contains(actionBtn)) {
        if (actionBtn.tagName === "A" && (actionBtn.hasAttribute("download") || (actionBtn.getAttribute("href") && actionBtn.getAttribute("href") !== "#"))) {
          return;
        }

        e.preventDefault();

        const action = actionBtn.dataset.action;

        if (action === "restart-chat") {
          CopilotManager.reset();
          return;
        }

        if (action === "reload") {
          window.location.reload();
          return;
        }

        if (action && action.includes("skill.")) {
          const skillId = action.split(".")[1];
          CopilotManager.trigger(skillId);
          setTimeout(() => {
            this.input.focus();
          });
          return;
        }

        if (action) {
          this._emit("fc/chat/action", { action });
        }
      }
    });
  },

  _bindInputEvents() {
    this.button.addEventListener("click", (e) => {
      e.preventDefault();
      this._emitSubmit();
    });

    this.input.addEventListener("keyup", (e) => {
      if (e.key === "Enter") this._emitSubmit();
    });
  },

  _emitSubmit() {
    if (this.button?.hasAttribute("disabled") || this.button?.classList.contains("desabilitado")) return;
    const msg = this.input.value.trim();
    this._emit("fc/chat/submit", { message: msg });
  },

  _appendTemplate(template, content) {
    if (!template || !this.container) return;
    const clone = template.content.cloneNode(true);
    clone.querySelector(".fs-chat__content").innerHTML = content;
    this.container.appendChild(clone);
    this._scrollToBottom();

    return this.container.lastElementChild;
  },

  _renderActions(message, actions) {
    const oldButtons = this.container.querySelectorAll(
      ".btn-chat-action:not([disabled])",
    );
    oldButtons.forEach((btn) => {
      if (btn.tagName === "BUTTON") btn.disabled = true;
      else btn.classList.add("disabled");
    });

    const actionContainer = message.querySelector(".fs-chat-acoes");

    if (!actionContainer) return;

    actionContainer.innerHTML = "";

    for (const item of actions) {
      const { action = "", label = "", url = "", download = false } = item;

      if (url || download) {
        const downloadAttr = download ? "download" : "";
        const targetAttr = url ? 'target="_blank"' : "";
        const hrefAttr = url ? `href="${url}"` : 'href="#"';
        const html = `<a class="btn-chat-action" ${hrefAttr} ${downloadAttr} ${targetAttr} data-action="${action}">${label}</a>`;
        actionContainer.insertAdjacentHTML("beforeend", html);
      } else {
        const html = `<button class="btn-chat-action" data-action="${action}">${label}</button>`;
        actionContainer.insertAdjacentHTML("beforeend", html);
      }
    }
  },

  _scrollToBottom() {
    if (this.container) this.container.scrollTop = this.container.scrollHeight;
  },

  _emit(eventName, detail = {}) {
    this.root.dispatchEvent(new CustomEvent(eventName, { detail }));
  },
};
