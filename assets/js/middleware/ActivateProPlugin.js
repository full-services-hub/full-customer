import { Chat } from "../core/Chat.js";
import { ApiService } from "../utils/ApiService.js";
import { generateId } from "../utils/functions.js";

export const ActivateProPlugin = {
  _working: false,
  _beforeUnloadHandler: null,

  _enableBeforeUnload() {
    if (!this._beforeUnloadHandler) {
      this._beforeUnloadHandler = (e) => {
        if (!this._working) return;
        e.preventDefault();
        e.returnValue = "";
        return "";
      };
      window.addEventListener("beforeunload", this._beforeUnloadHandler);
    }
  },

  _disableBeforeUnload() {
    if (this._beforeUnloadHandler) {
      window.removeEventListener("beforeunload", this._beforeUnloadHandler);
      this._beforeUnloadHandler = null;
    }
  },

  async _middleware(manager, skill, actions, msg) {
    if (skill.id !== "activateProPlugin") {
      return;
    }

    this._working = true;
    this._enableBeforeUnload();
    const workingPlugins = actions;

    try {
      for (const plugin of workingPlugins) {
      if (plugin.simpleRest) {
        continue;
      }

      const processId = generateId();
      const isAddon = plugin.extraProps?.isAddon || false;

      const callbacks = {
        activeStep: 1,
        isAddon: isAddon,
        start: () =>
          Chat.sendLicensingCard(processId, manager._activeAgent.name, plugin.imageUrl, isAddon, plugin.extraProps?.addonName),
        progress: (stepIndex, status, msg, percent = null) =>
          Chat.updateLicensingCard(processId, stepIndex, status, msg, percent),
        onSuccess: (msg) => {
          const finalStep = isAddon ? 2 : 4;
          Chat.updateLicensingCard(processId, finalStep, "completed", msg, null, [
            {
              label: 'Recarregar página',
              action: 'reload',
            }
          ]);
        },
        onError: (msg) => {
          const step = callbacks.activeStep || 1;
          Chat.updateLicensingCard(processId, step, "failed", msg, null, [
            {
              label: 'Suporte',
              action: 'help',
            },
            {
              label: 'Recarregar página',
              action: 'reload',
            }
          ]);
        },
      };

      const stopPolling = this._startPolling(processId, callbacks);

      try {
        callbacks.start();
        
        if (!isAddon) {
          await this.processFullActivation(
            processId,
            plugin.extraProps.pluginSlug,
            callbacks,
          );
        }

        await this.processInstallation(
          processId,
          plugin.extraProps.pluginSlug,
          callbacks,
        );
        await this.processWordPressActivation(
          processId,
          plugin.extraProps.plugin,
          callbacks,
        );

        if (!isAddon) {
          await this.processLicense(
            processId,
            plugin.extraProps.pluginSlug,
            callbacks,
            manager,
          );
        }

        callbacks.onSuccess(`🚀 Concluído com sucesso!`);
        await manager._loadAndRenderSkills();
      } catch (err) {
        callbacks.onError(`❌ Erro: ${err.message}`);
      } finally {
        stopPolling();
      }
    }
  } finally {
    this._working = false;
    this._disableBeforeUnload();
  }
},

  _startPolling(processId, callbacks) {
    let lastDisplayedIndex = 0;
    const { restUrl, nonce } = window.fcData;

    let isPolling = true;
    let timerId = null;

    const poll = async () => {
      if (!isPolling) return;

      try {
        const res = await fetch(`${restUrl}/actions/execution/${processId}`, {
          headers: {
            "X-WP-Nonce": nonce,
          },
        });
        if (res.ok) {
          const data = await res.json();
          const states = data.states || [];

          if (states.length > lastDisplayedIndex) {
            for (let i = lastDisplayedIndex; i < states.length; i++) {
              const statusText = states[i];
              if (statusText) {
                if (callbacks && typeof callbacks.progress === "function") {
                  callbacks.progress(callbacks.activeStep || 1, "processing", statusText);
                }
              }
            }
            lastDisplayedIndex = states.length;
          }
        }
      } catch (e) {
        console.warn("[Status Polling] Erro ao buscar status de execução", e);
      }

      if (isPolling) {
        timerId = setTimeout(poll, 1000);
      }
    };

    poll();

    return () => {
      isPolling = false;
      if (timerId) {
        clearTimeout(timerId);
      }
    };
  },

  // ─── Processos ────────────────────────────────────────────

  async processFullActivation(processId, pluginSlug, callbacks) {
    if (callbacks && callbacks.activeStep !== undefined) {
      callbacks.activeStep = 1;
    }

    const progress = (status, msg, percent = null) => {
      if (typeof callbacks === "function") {
        callbacks(msg);
      } else if (callbacks && typeof callbacks.progress === "function") {
        if (callbacks.activeStep !== undefined) {
          callbacks.progress(1, status, msg, percent);
        } else {
          callbacks.progress(msg);
        }
      }
    };

    progress("processing", "Solicitando ativação no painel da FULL...");

    const res = await ApiService.post(
      `/actions/plugins/full-activate/${processId}`,
      {
        pluginSlug: pluginSlug,
      },
    );

    if (!res.success) {
      throw new Error(res.error || "Falha na ativação");
    }

    progress("completed", "Ativação solicitada com sucesso!");
  },

  async processInstallation(processId, pluginSlug, callbacks) {
    if (callbacks && callbacks.activeStep !== undefined) {
      callbacks.activeStep = callbacks.isAddon ? 1 : 2;
    }

    const progress = (status, msg, percent = null) => {
      if (typeof callbacks === "function") {
        callbacks(msg);
      } else if (callbacks && typeof callbacks.progress === "function") {
        if (callbacks.activeStep !== undefined) {
          callbacks.progress(callbacks.activeStep, status, msg, percent);
        } else {
          callbacks.progress(msg);
        }
      }
    };

    progress("processing", "Buscando dados do plugin no repositório...");

    const infoRes = await ApiService.get(`/actions/plugins/info/${pluginSlug}`);
    if (!infoRes.success || !infoRes.data?.package) {
      throw new Error(infoRes.error || "Não foi possível obter dados do plugin.");
    }

    const pluginData = infoRes.data;

    // Se o plugin já estiver instalado localmente e atualizado
    if (pluginData.local?.installed && pluginData.local?.upToDate) {
      progress("processing", "Plugin já instalado localmente na versão mais recente. Validando dependências...");
      
      const installRes = await ApiService.post(`/actions/plugins/install/${processId}`, {
        pluginSlug: pluginSlug,
      });

      if (!installRes.success) {
        throw new Error(installRes.error || "Falha na validação final da instalação.");
      }

      progress("completed", "Plugin verificado e instalado.");
      return;
    }

    progress("processing", "Iniciando download do plugin pelo seu navegador...");

    const response = await fetch(pluginData.package);
    if (!response.ok) {
      throw new Error(`Falha ao baixar o plugin (${response.statusText})`);
    }

    let downloadedBytes = 0;
    const reader = response.body.getReader();
    const chunks = [];

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      chunks.push(value);
      downloadedBytes += value.length;

      const loadedMb = (downloadedBytes / (1024 * 1024)).toFixed(2);
      progress("processing", `Baixando arquivo do plugin... (${loadedMb} MB baixados)`);
    }

    const blob = new Blob(chunks);
    progress("processing", "Download concluído! Preparando para enviar...");

    const chunkSize = 1024 * 1024; // 1MB por chunk
    const totalChunks = Math.ceil(blob.size / chunkSize);
    const fileName = `${pluginSlug}.zip`;

    for (let i = 0; i < totalChunks; i++) {
      const start = i * chunkSize;
      const end = Math.min(start + chunkSize, blob.size);
      const chunkSlice = blob.slice(start, end);

      const formData = new FormData();
      formData.append("chunk", chunkSlice, fileName);
      formData.append("fileName", fileName);
      formData.append("chunkIndex", i);
      formData.append("totalChunks", totalChunks);
      formData.append("pluginSlug", pluginSlug);

      const uploadPercent = Math.round((i / totalChunks) * 100);
      progress("processing", "Enviando arquivo do plugin...", uploadPercent);

      const uploadRes = await ApiService.postFormData(
        `/actions/plugins/upload-chunk/${processId}`,
        formData,
      );

      if (!uploadRes.success) {
        throw new Error(uploadRes.error || "Falha ao enviar pedaço do plugin.");
      }
    }

    progress("processing", "Descompactando e finalizando instalação no seu WordPress...");

    const installRes = await ApiService.post(`/actions/plugins/install/${processId}`, {
      pluginSlug: pluginSlug,
    });

    if (!installRes.success) {
      throw new Error(installRes.error || "Falha ao finalizar a instalação do plugin.");
    }

    progress("completed", "Plugin instalado com sucesso!");
  },

  async processWordPressActivation(processId, plugin, callbacks) {
    if (callbacks && callbacks.activeStep !== undefined) {
      callbacks.activeStep = callbacks.isAddon ? 2 : 3;
    }

    const progress = (status, msg, percent = null) => {
      if (typeof callbacks === "function") {
        callbacks(msg);
      } else if (callbacks && typeof callbacks.progress === "function") {
        if (callbacks.activeStep !== undefined) {
          callbacks.progress(callbacks.activeStep, status, msg, percent);
        } else {
          callbacks.progress(msg);
        }
      }
    };

    progress("processing", "Ativando plugin no seu WordPress...");

    const res = await ApiService.post(
      `/actions/plugins/wordpress-activate/${processId}`,
      {
        plugin: plugin,
      },
    );

    if (!res.success) {
      throw new Error(res.error || "Falha ao ativar o plugin.");
    }

    progress("completed", "Plugin ativado com sucesso!");
  },

  async processLicense(processId, pluginSlug, callbacks, manager = null) {
    if (callbacks && callbacks.activeStep !== undefined) {
      callbacks.activeStep = 4;
    }

    const progress = (status, msg, percent = null) => {
      if (typeof callbacks === "function") {
        callbacks(msg);
      } else if (callbacks && typeof callbacks.progress === "function") {
        if (callbacks.activeStep !== undefined) {
          callbacks.progress(4, status, msg, percent);
        } else {
          callbacks.progress(msg);
        }
      }
    };

    progress("processing", "E agora vamos inserir a licença oficial do plugin...");

    let step = "";
    let state = {};

    while (true) {
      const res = await ApiService.post(`/actions/plugins/license/${processId}`, {
        pluginSlug: pluginSlug,
        step: step,
        state: state,
      });

      if (!res.success) {
        throw new Error(res.error || "Falha na ativação da licença.");
      }

      const result = res.result || {};

      if (res.message) {
        progress("processing", res.message);
      }

      if (result.completed === undefined || result.completed === true) {
        progress("completed", res.message || "Licença instalada com sucesso!");
        
        if (result.redirectUrl) {
          progress("processing", "Redirecionando...");
          if (manager) {
            manager._working = false;
          }
          this._working = false;
          this._disableBeforeUnload();
          window.location.href = result.redirectUrl;
        }
        break;
      }

      step = result.step;
      state = result.state || {};
    }
  },
};
