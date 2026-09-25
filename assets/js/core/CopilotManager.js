/**
 * CopilotManager
 * Gerenciador central de Modelos, Agentes e Habilidades (Actions).
 */
import { Chat } from "../core/Chat.js";
import { ApiService } from "../utils/ApiService.js";
import { WelcomeService } from "./WelcomeService.js";

export const CopilotManager = {
  waitingUserPersonalAnswer: false,

  _initialized: false,
  _working: false,
  _root: null,
  _cartaoAcaoEl: null,

  _suggestionsEl: null,

  // Elementos do seletor de Modelo
  _modeloGatilhoEl: null,
  _modeloMenuContainerEl: null,
  _modeloIconeEl: null,
  _modeloNomeEl: null,

  // Elementos do seletor de Agente
  _agenteGatilhoEl: null,
  _agenteMenuContainerEl: null,
  _agenteIconeEl: null,
  _agenteNomeEl: null,
  _agenteSeletorContainerEl: null,

  // Elementos da nova UI de Ação Ativa
  _acaoAtivaContainerEl: null,
  _acaoAtivaNomeEl: null,
  _acaoAtivaRemoverEl: null,

  _skills: [],        // Modelos
  _activeSkill: null, // Modelo Ativo
  _activeAgent: null, // Agente Ativo
  _selectedItems: [], // Ações selecionadas (contém no máx 1 ação ativa no novo padrão)

  _middlewares: [],
  _keepActiveAction: false,

  async init(root) {
    this._root = root;
    this._cartaoAcaoEl = root.querySelector(".fs-cartao-acao");

    window.addEventListener("beforeunload", (e) => {
      if (this._working) {
        e.preventDefault();
        e.returnValue = "";
        return "";
      }
    });

    this._suggestionsEl = root.querySelector("#copilotSugestoes");

    this._modeloGatilhoEl = root.querySelector("#modeloGatilho");
    this._modeloMenuContainerEl = root.querySelector("#modeloMenuContainer");
    this._modeloIconeEl = root.querySelector("#modeloIcone");
    this._modeloNomeEl = root.querySelector("#modeloNome");

    this._agenteGatilhoEl = root.querySelector("#agenteGatilho");
    this._agenteMenuContainerEl = root.querySelector("#agenteMenuContainer");
    this._agenteIconeEl = root.querySelector("#agenteIcone");
    this._agenteNomeEl = root.querySelector("#agenteNome");
    this._agenteSeletorContainerEl = root.querySelector("#agenteSeletorContainer");

    // Mapeamento dos novos elementos de Ação Ativa
    this._acaoAtivaContainerEl = root.querySelector("#fsAcaoAtivaContainer");
    this._acaoAtivaNomeEl = root.querySelector("#fsAcaoAtivaNome");
    this._acaoAtivaRemoverEl = root.querySelector("#fsAcaoAtivaRemover");

    if (!this._modeloMenuContainerEl || !this._agenteMenuContainerEl) {
      return;
    }

    this._bindMenuEvents();
    this._bindInputEvents();
    this._bindSuggestionClick();
    this._bindNewUiEvents();

    if (!this._initialized) {
      this._bindSkillChange();
      this._bindChatSubmit();
      this._bindActions();

      this._bindSimpleActionsEvents();

      this._initialized = true;
    }

    await this._loadAndRenderSkills();
  },

  async _bindSimpleActionsEvents() {
    this._root.addEventListener(
      "fc/simple-action/processed",
      async ({ detail: { action, response } }) => {
        if (action.id === "CleanUpCache" && response.success) {
          await this._loadAndRenderSkills();
        }
      },
    );

    this._root.addEventListener(
      "fc/simple-action/processed",
      ({ detail: { action, response } }) => {
        if (response.result && response.result.redirectUrl) {
          window.location.href = response.result.redirectUrl;
        }
      },
    );

    this._root.addEventListener(
      "fc/simple-action/processed",
      async ({ detail: { action, response } }) => {
        if (action.id === "disconnectAccount" && response.success) {
          Chat.sendLoadingMessage();
          setTimeout(() => {
            this.reset();
          }, 1000);
        }
      },
    );
  },

  async trigger(skillId, agentId = "", action = "", msg = "") {
    const model = this._skills.find((s) => s.id == skillId);
    if (!model) {
      return;
    }

    if (!model.isAvailable) {
      Chat.sendCopilotMissingToolOrSkillMessage();
      return;
    }

    // Seleciona o Modelo
    this._modeloMenuContainerEl
      ?.querySelectorAll(".fs-skill-chip__item")
      .forEach((el) => el.classList.remove("fs-skill-chip__item--ativo"));

    const modelItemEl = this._modeloMenuContainerEl?.querySelector(
      `[data-skill="${model.id}"]`,
    );
    if (modelItemEl) modelItemEl.classList.add("fs-skill-chip__item--ativo");

    this._updateModeloVisuals(model);
    this._activeSkill = model;

    // Renderiza e seleciona os Agentes
    this._renderAgents(model);

    let agent = null;
    if (agentId) {
      agent = model.agents.find((a) => a.id == agentId);
    } else if (model.agents.length > 0) {
      agent = model.agents[0];
    }

    if (agent) {
      this._selectAgent(agent);
    }

    this._clearUI(true);

    if (action) {
      this._selectAction(action);
      const agentName = this._activeAgent ? ` para o agente ${this._activeAgent.name}` : "";
      Chat.sendUserMessage(`Executar ação ${action.name}${agentName}`);
    }

    await this._execute(msg);
  },

  // ─── Renderização do Menu de Modelos ───────────────────────

  async _loadAndRenderSkills() {
    try {
      const response = await ApiService.get("/skills");
      if (!response.success || !response.skills)
        throw new Error("Formato inválido.");

      if (window.fcData) {
        window.fcData.connected = response.connected;
      }

      this._skills = response.skills;
      this._skills.sort((a, b) =>
        a.isAvailable === b.isAvailable ? 0 : a.isAvailable ? -1 : 1,
      );

      const firstAvailable = this._skills.find((s) => s.isDefault);

      this._modeloMenuContainerEl.innerHTML = this._skills
        .map((model) => {
          const isAtivoClass =
            model.id === firstAvailable?.id ? "fs-skill-chip__item--ativo" : "";
          const stateClass = model.isAvailable
            ? "fs-skill-chip__item--available"
            : "fs-skill-chip__item--embreve";
          const badgeHtml = model.isSoon
            ? `<span class="fs-emblema fs-emblema--aviso fs-emblema--pequeno">Em breve</span>`
            : "";

          return `
          <div data-skill="${model.id}" class="fs-skill-chip__item ${stateClass} ${isAtivoClass}">
            <div class="fs-skill-chip__item-esq">
              <div class="fs-skill-chip__item-header">
                <img src="${model.imageUrl}" alt="Ícone" width="16" height="16" />
                <span class="fs-skill-chip__item-nome">${model.name}</span>
              </div>
              <span class="fs-skill-chip__item-desc">${model.shortDescription}</span>
            </div>
            ${badgeHtml}
          </div>`;
        })
        .join("");

      if (firstAvailable) {
        this._updateModeloVisuals(firstAvailable);
        this._activeSkill = firstAvailable;
        this._renderAgents(firstAvailable);

        if (firstAvailable.agents && firstAvailable.agents.length > 0) {
          this._selectAgent(firstAvailable.agents[0]);
        }
      }
    } catch (error) {
      console.error("[CopilotManager] Erro ao carregar skills:", error);
    }
  },

  _renderAgents(model) {
    if (!model.agents || model.agents.length === 0) {
      this._agenteSeletorContainerEl.style.display = "none";
      return;
    }

    this._agenteSeletorContainerEl.style.display = "flex";

    const isReadOnly = model.agents.every((a) => a.isReadOnly) || model.agents.length === 1;

    if (isReadOnly) {
      this._agenteGatilhoEl.setAttribute("disabled", "disabled");
      this._agenteSeletorContainerEl.classList.add("desabilitado");
    } else {
      this._agenteGatilhoEl.removeAttribute("disabled");
      this._agenteSeletorContainerEl.classList.remove("desabilitado");
    }

    this._agenteMenuContainerEl.innerHTML = model.agents
      .map((agent, index) => {
        const isAtivoClass = index === 0 ? "fs-skill-chip__item--ativo" : "";
        return `
        <div data-agent="${agent.id}" class="fs-skill-chip__item fs-skill-chip__item--available ${isAtivoClass}">
          <div class="fs-skill-chip__item-esq">
            <div class="fs-skill-chip__item-header">
              <img src="${agent.imageUrl}" alt="Ícone" width="16" height="16" />
              <span class="fs-skill-chip__item-nome">${agent.name}</span>
            </div>
          </div>
        </div>`;
      })
      .join("");
  },

  _selectAgent(agent) {
    this._activeAgent = agent;

    if (this._agenteIconeEl) {
      this._agenteIconeEl.src = agent.imageUrl;
    }
    if (this._agenteNomeEl) {
      this._agenteNomeEl.innerHTML = agent.name;
    }

    this._agenteMenuContainerEl
      ?.querySelectorAll(".fs-skill-chip__item")
      .forEach((el) => {
        el.classList.toggle("fs-skill-chip__item--ativo", el.dataset.agent === agent.id);
      });

    this._removeAcaoAtiva(false);
    this._clearUI(false);

    Chat.setSkillContext({
      inputPlaceholder: this._activeSkill.inputPlaceholder
    });
  },

  _updateModeloVisuals(model) {
    if (this._modeloIconeEl) {
      this._modeloIconeEl.src = model.imageUrl;
    }
    if (this._modeloNomeEl) {
      this._modeloNomeEl.innerHTML = model.name;
    }
  },

  _dispatchSkillChange(skillData) {
    this._root.dispatchEvent(
      new CustomEvent("fc/chat/skill-changed", {
        detail: { skill: skillData },
      }),
    );
  },

  // ─── Bindings de Eventos ──────────────────────────────────

  _bindMenuEvents() {
    this._modeloMenuContainerEl?.addEventListener("click", (e) => {
      const itemEl = e.target.closest(".fs-skill-chip__item--available");
      if (!itemEl) return;

      const skillId = itemEl.dataset.skill;
      const selectedModel = this._skills.find((s) => s.id === skillId);

      if (!selectedModel) return;

      this._modeloMenuContainerEl
        .querySelectorAll(".fs-skill-chip__item")
        .forEach((el) => {
          el.classList.remove("fs-skill-chip__item--ativo");
        });
      itemEl.classList.add("fs-skill-chip__item--ativo");

      if (typeof bootstrap !== "undefined" && bootstrap.Dropdown) {
        bootstrap.Dropdown.getOrCreateInstance(this._modeloGatilhoEl).hide();
      }

      this._updateModeloVisuals(selectedModel);
      this._activeSkill = selectedModel;
      this._renderAgents(selectedModel);

      if (selectedModel.agents && selectedModel.agents.length > 0) {
        this._selectAgent(selectedModel.agents[0]);
      }
    });

    this._agenteMenuContainerEl?.addEventListener("click", (e) => {
      const itemEl = e.target.closest(".fs-skill-chip__item--available");
      if (!itemEl) return;

      const agentId = itemEl.dataset.agent;
      const selectedAgent = this._activeSkill.agents.find((a) => a.id === agentId);

      if (!selectedAgent) return;

      if (typeof bootstrap !== "undefined" && bootstrap.Dropdown) {
        bootstrap.Dropdown.getOrCreateInstance(this._agenteGatilhoEl).hide();
      }
      this._selectAgent(selectedAgent);
    });
  },

  _bindSkillChange() {
    this._root.addEventListener("fc/chat/skill-changed", async ({ detail }) => {
      const { skill } = detail;

      this._clearUI();

      this._activeSkill = skill;

      Chat.setSkillContext(skill);
    });
  },

  _bindChatSubmit() {
    this._root.addEventListener("fc/chat/submit", async ({ detail }) => {
      if (this._selectedItems.length > 0) {
        const agentName = this._activeAgent ? ` para o agente ${this._activeAgent.name}` : "";
        Chat.sendUserMessage(
          `Executar ação ${this._selectedItems.map((p) => p.name).join(", ")}${agentName}`,
        );
      }
      await this._execute(detail.message);
    });
  },

  _bindInputEvents() {
    let debounceTimer;

    const handleInput = (e) => {
      this._syncButtonState();

      if (!this._activeAgent?.actions) return;

      clearTimeout(debounceTimer);

      const query = Chat.input.value.trim();
      if (query.length === 0) {
        this._filterSuggestions();
      } else {
        debounceTimer = setTimeout(() => {
          this._filterSuggestions();
        }, 150);
      }
    };

    Chat.input?.addEventListener("input", handleInput);
    Chat.input?.addEventListener("focus", handleInput);

    document.addEventListener("click", (e) => {
      if (!Chat.input || !this._suggestionsEl) return;

      const isClickInsideInput = Chat.input.contains(e.target);
      const isClickInsideSuggestions = this._suggestionsEl.contains(e.target);

      if (!isClickInsideInput && !isClickInsideSuggestions) {
        if (typeof bootstrap !== "undefined" && bootstrap.Dropdown) {
          const bsDropdown = bootstrap.Dropdown.getInstance(Chat.input);
          if (bsDropdown) {
            bsDropdown.hide();
          }
        }
      }
    });
  },

  _bindSuggestionClick() {
    this._suggestionsEl?.addEventListener("click", (e) => {
      const triggerEl = e.target.closest(".fs-copilot-addons-accordion-trigger");
      if (triggerEl) {
        e.preventDefault();
        e.stopPropagation();
        const contentEl = triggerEl.nextElementSibling;
        const arrowEl = triggerEl.querySelector(".fs-accordion-arrow");
        if (contentEl) {
          contentEl.classList.toggle("d-none");
          contentEl.classList.toggle("d-flex");
          if (arrowEl) {
            arrowEl.style.transform = contentEl.classList.contains("d-none") ? "rotate(0deg)" : "rotate(180deg)";
          }
        }
        return;
      }

      const accordionContentEl = e.target.closest(".fs-copilot-addons-accordion-content");
      const isSuggestion = e.target.closest(".fs-copilot-sugestao");
      if (accordionContentEl && !isSuggestion) {
        e.stopPropagation();
        e.preventDefault();
        return;
      }

      const itemEl = e.target.closest(".fs-copilot-sugestao");
      if (!itemEl) return;

      const dataItem = this._activeAgent?.actions.find(
        (p) => p.id == itemEl.dataset.id,
      );
      if (dataItem) this._addTag(dataItem);
    });
  },

  _bindNewUiEvents() {
    // Escuta clique no botão de remover Ação Ativa
    this._acaoAtivaRemoverEl?.addEventListener("click", () => {
      this._removeAcaoAtiva();
    });
  },

  _bindActions() {
    this._root.addEventListener("fc/chat/action", async ({ detail }) => {
      const { action } = detail;

      if (action === "help") {
        this._showSidebar();
        return;
      }

      const actionItem = this._activeAgent.actions.find((p) => p.id === action);

      this.trigger(this._activeSkill.id, this._activeAgent.id, actionItem);
    });
  },

  // ─── Lógica de UI (Ações e Sugestões) ────────────

  _showSidebar() {
    const offcanvasElement = document.getElementById("sidebarAjuda");
    if (typeof bootstrap !== "undefined" && bootstrap.Offcanvas && offcanvasElement) {
      const bsOffcanvas =
        bootstrap.Offcanvas.getOrCreateInstance(offcanvasElement);
      bsOffcanvas.show();
    }
  },

  _filterSuggestions() {
    if (!this._activeAgent?.actions) return;

    // Se já tiver uma ação selecionada, não exibe as sugestões do autocompletar
    if (this._selectedItems.length > 0) {
      if (typeof bootstrap !== "undefined" && bootstrap.Dropdown) {
        const bsDropdown = bootstrap.Dropdown.getInstance(Chat.input);
        if (bsDropdown) bsDropdown.hide();
      }
      return;
    }

    const query = Chat.input.value.trim().toLowerCase();
    const matches = query
      ? this._activeAgent.actions.filter((item) =>
          item.name?.toLowerCase().includes(query),
        )
      : this._activeAgent.actions;

    this._renderSuggestions(matches, query);
  },

  _renderSuggestions(items, query = "") {
    let bsDropdown = null;
    if (typeof bootstrap !== "undefined" && bootstrap.Dropdown) {
      bsDropdown = bootstrap.Dropdown.getOrCreateInstance(Chat.input);
    }

    const available = items.filter((item) => item.showInActionsDropdown);

    if (!available.length || this.waitingUserPersonalAnswer) {
      if (bsDropdown) bsDropdown.hide();
      return;
    }

    const normalActions = available.filter(item => !item.extraProps?.isAddon);
    const addonActions = available.filter(item => item.extraProps?.isAddon);

    const defaultIcon = (window.fcData?.pluginUrl || '') + 'assets/images/icons/energy.svg';

    let html = "";

    if (normalActions.length > 0) {
      html += normalActions
        .map(
          (item) => {
            const imgUrl = (item.imageUrl && !item.imageUrl.endsWith('/')) ? item.imageUrl : defaultIcon;
            return `
            <a href="#" class="dropdown-item d-flex align-items-center fs-copilot-sugestao" data-id="${item.id}">
              <img src="${imgUrl}" alt="" class="fs-copilot-sugestao__icone" />
              <div class="fs-copilot-sugestao__texto">
                <span class="fs-copilot-sugestao__nome d-block fw-bold text-dark">${this._highlight(item.name, query)}</span>
                ${item.desc ? `<span class="fs-copilot-sugestao__desc text-muted small">${item.desc}</span>` : ""}
              </div>
            </a>
          `;
          }
        )
        .join("");
    }

    if (addonActions.length > 0) {
      if (normalActions.length > 0) {
        html += `<div class="dropdown-divider"></div>`;
      }

      html += `
        <div class="fs-copilot-addons-accordion">
          <button class="dropdown-item fs-copilot-addons-accordion-trigger px-3 mb-2 d-flex align-items-center justify-content-between w-100 border-0 bg-transparent fw-bold" type="button">
            <span class="fw-bold text-dark">Addons Disponíveis (${addonActions.length})</span>
            <span class="fs-accordion-arrow">▼</span>
          </button>
          <div class="fs-copilot-addons-accordion-content d-none flex-column">
      `;

      html += addonActions
        .map(
          (item) => {
            const imgUrl = (item.imageUrl && !item.imageUrl.endsWith('/')) ? item.imageUrl : defaultIcon;
            return `
            <a href="#" class="dropdown-item d-flex align-items-center fs-copilot-sugestao" data-id="${item.id}">
              <img src="${imgUrl}" alt="" class="fs-copilot-sugestao__icone" />
              <div class="fs-copilot-sugestao__texto">
                <span class="fs-copilot-sugestao__nome d-block fw-bold text-dark">${this._highlight(item.name, query)}</span>
                ${item.desc ? `<span class="fs-copilot-sugestao__desc text-muted small">${item.desc}</span>` : ""}
              </div>
            </a>
          `;
          }
        )
        .join("");

      html += `
          </div>
        </div>
      `;
    }

    if (this._suggestionsEl.innerHTML !== html) {
      this._suggestionsEl.innerHTML = html;
    }

    if (bsDropdown) bsDropdown.show();
  },

  // Compatibilidade com eventos legados de sugestão que disparam _addTag
  _addTag(item) {
    this._selectAction(item);
  },

  _selectAction(item) {
    if (this.waitingUserPersonalAnswer) {
      return;
    }

    // Fecha e limpa o dropdown de sugestões enquanto o input ainda está habilitado
    if (typeof bootstrap !== "undefined" && bootstrap.Dropdown) {
      const bsDropdown = bootstrap.Dropdown.getInstance(Chat.input);
      if (bsDropdown) bsDropdown.hide();
    }
    if (this._suggestionsEl) {
      this._suggestionsEl.classList.remove("show");
      this._suggestionsEl.innerHTML = "";
    }

    this._selectedItems = [item];

    // Atualizar Barra de Ação Ativa
    if (this._acaoAtivaContainerEl) {
      if (this._acaoAtivaNomeEl) {
        this._acaoAtivaNomeEl.innerHTML = item.name;
      }
      this._acaoAtivaContainerEl.classList.remove("d-none");
      this._acaoAtivaContainerEl.classList.add("d-flex");
    }

    // Limpa o input de texto e altera o placeholder/estado para instruções
    if (Chat.input) {
      Chat.input.value = "";
      if (item.requiresInput || item.id === "connectAccount") {
        Chat.input.disabled = false;
        Chat.input.removeAttribute("disabled");
        Chat.input.placeholder = item.inputPlaceholder || (item.id === "connectAccount" ? "Digite o e-mail de compra" : "Digite a sua solicitação...");
        Chat.input.focus();
      } else {
        Chat.input.disabled = true;
        Chat.input.setAttribute("disabled", "disabled");
        Chat.input.placeholder = "Esta ação não requer informações adicionais";
        Chat.input.blur();
      }
    }

    this._syncButtonState();
  },

  _removeAcaoAtiva(sync = true) {
    this._selectedItems = [];
    if (this._acaoAtivaContainerEl) {
      this._acaoAtivaContainerEl.classList.remove("d-flex");
      this._acaoAtivaContainerEl.classList.add("d-none");
    }
    if (Chat.input) {
      Chat.input.disabled = false;
      Chat.input.removeAttribute("disabled");
      Chat.input.placeholder = this._activeSkill?.inputPlaceholder || "Digite sua mensagem ou escolha uma ação...";
    }
    if (sync) {
      this._syncButtonState();
    }
  },

  _syncButtonState() {
    if (this.waitingUserPersonalAnswer) {
      Chat.toggleSubmitButton(true);
      return;
    }

    const hasSelectedAction = this._selectedItems.length > 0;
    const hasInputText = Chat.input?.value.trim().length > 0;
    const isInputRequiredAction =
      hasSelectedAction &&
      (this._selectedItems[0]?.requiresInput ||
        this._selectedItems[0]?.id === "connectAccount");

    let isEnabled = false;

    if (isInputRequiredAction) {
      isEnabled = hasInputText;
    } else if (hasSelectedAction) {
      isEnabled = true;
    } else {
      isEnabled = hasInputText;
    }

    Chat.toggleSubmitButton(!isEnabled);
  },

  // ─── Execução Genérica ────────────────────────────────────

  keepActiveAction() {
    this._keepActiveAction = true;
  },

  addMiddleware(middleware) {
    if (typeof middleware !== "function") return;

    if (this._middlewares.includes(middleware)) return;

    this._middlewares.push(middleware);
  },

  async _execute(msg) {
    if (this._working) {
      Chat.sendCopilotMessage(
        "Só um pouquinho, ainda estou trabalhando na sua solicitação anterior.",
      );
      return;
    }

    Chat.toggleSubmitButton(true);

    if (
      !this.waitingUserPersonalAnswer &&
      this._activeAgent?.actions &&
      this._selectedItems.length === 0
    ) {
      Chat.sendCopilotMissingToolOrSkillMessage();
      return;
    }

    try {
      this._working = true;
      this._cartaoAcaoEl?.classList.add("bloqueado");
      this._clearUI(false);

      const actions =
        this._selectedItems.length > 0 ? this._selectedItems : null;

      if (msg) Chat.sendUserMessage(msg);

      for (const mw of this._middlewares) {
        await mw(this, this._activeSkill, actions, msg);
      }
    } catch (error) {
      console.error("Falha na execução do middleware:", error);
      Chat.sendCopilotMessage("Ocorreu um erro ao processar sua solicitação.");
    } finally {
      this._working = false;
      this._cartaoAcaoEl?.classList.remove("bloqueado");
      if (this._keepActiveAction && actions && actions[0]) {
        this._keepActiveAction = false;
        this._selectAction(actions[0]);
      } else {
        this._selectedItems = [];
        this._removeAcaoAtiva(false);
        this._syncButtonState();
      }
    }
  },

  _highlight(text, query) {
    if (!query || !text) return text;
    const safe = query.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const regex = new RegExp(`(${safe})`, "gi");
    return text.replace(regex, "<mark>$1</mark>");
  },

  _clearUI(clearSelected = true) {
    if (clearSelected) {
      this._removeAcaoAtiva(false);
    }
    if (this._suggestionsEl) {
      if (typeof bootstrap !== "undefined" && bootstrap.Dropdown) {
        const bsDropdown = bootstrap.Dropdown.getInstance(Chat.input);
        if (bsDropdown) bsDropdown.hide();
      }
      this._suggestionsEl.classList.remove("show");
      this._suggestionsEl.innerHTML = "";
    }
  },

  async reset() {
    if (Chat.container) {
      Chat.container.innerHTML = "";
    }
    if (Chat.input) {
      Chat.input.value = "";
    }
    if (Chat.root) {
      Chat.root.classList.remove("chating");
    }

    this.waitingUserPersonalAnswer = false;
    this._selectedItems = [];
    this._clearUI(true);

    if (this._root) {
      this._root.dispatchEvent(new CustomEvent("fc/chat/reset"));
    }

    await this._loadAndRenderSkills();

    WelcomeService._greet(this._root);
  },
};
