(function () {
    var state = {
        targetConfig: null,
        activeRunId: null,
        activeRunStatus: '',
        activeRunDetail: null,
        lastRuns: [],
        pollTimer: null,
        pollIntervalMs: 0,
        processingRuns: {},
        blockedRuns: {},
        processControllers: {},
        genericLastResult: null,
        genericProcess: {
            page: null,
            urls: null,
            fields: null,
            raw: null,
            activeTab: 'page'
        },
        genericTemplates: [],
        articleStyles: [],
        modelProfiles: []
    };

    function $(selector, root) {
        return (root || document).querySelector(selector);
    }

    function $all(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function getAppConfig() {
        return window.aiditorContentIngest || {};
    }

    function getCurrentSettings() {
        return getAppConfig().settings || {};
    }

    function clampNumber(value, min, max, fallback) {
        var number = Number(value);

        if (!Number.isFinite(number)) {
            number = fallback;
        }

        return Math.max(min, Math.min(max, number));
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setNotice(element, message, type) {
        if (!element) {
            return;
        }

        element.textContent = message || '';
        element.classList.remove('is-success', 'is-error');

        if (type) {
            element.classList.add(type === 'error' ? 'is-error' : 'is-success');
        }
    }

    function isTerminalStatus(status) {
        return ['completed', 'completed_with_errors', 'failed', 'cancelled'].indexOf(String(status || '')) !== -1;
    }

    function isProcessableStatus(status) {
        return ['queued', 'running'].indexOf(String(status || '')) !== -1;
    }

    function getRunStatusLabel(status) {
        var labels = {
            queued: '排队中',
            running: '执行中',
            paused: '已暂停',
            completed: '已完成',
            completed_with_errors: '已完成（有错误）',
            failed: '失败',
            cancelled: '已取消'
        };

        return labels[String(status || '')] || String(status || '-');
    }

    function getItemStatusLabel(status) {
        var labels = {
            pending: '待处理',
            running: '处理中',
            created: '已创建',
            updated: '已更新',
            skipped: '已跳过',
            failed: '失败',
            cancelled: '已取消'
        };

        return labels[String(status || '')] || String(status || '-');
    }

    function getRunActionLabel(action) {
        var labels = {
            process: '立即处理',
            pause: '暂停',
            resume: '恢复',
            cancel: '取消',
            'retry-failed': '重新采集失败条目',
            delete: '删除记录'
        };

        return labels[String(action || '')] || String(action || '');
    }

    function getStatusPillClass(status) {
        var value = String(status || '');

        if (value === 'failed' || value === 'completed_with_errors') {
            return 'is-danger';
        }

        if (value === 'running' || value === 'created' || value === 'updated' || value === 'completed') {
            return 'is-success';
        }

        return 'is-muted';
    }

    function api(path, options) {
        var config = getAppConfig();
        var request = Object.assign(
            {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': config.nonce || '',
                    'Content-Type': 'application/json'
                }
            },
            options || {}
        );

        return fetch((config.restUrl || '') + path, request).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    var message = (data && data.message) || '请求失败。';
                    throw new Error(message);
                }

                return data;
            });
        });
    }

    function getPollIntervalMs() {
        var settings = getCurrentSettings();
        return clampNumber(settings.queue_poll_interval, 2, 30, 3) * 1000;
    }

    function getConcurrencyTarget() {
        var settings = getCurrentSettings();
        return clampNumber(settings.queue_concurrency, 1, 20, 4);
    }

    function getLocalWorkerCount(runId) {
        if (!runId) {
            return 0;
        }

        return Number(state.processingRuns[runId] || 0);
    }

    function changeLocalWorkerCount(runId, delta) {
        var next;

        if (!runId) {
            return;
        }

        next = getLocalWorkerCount(runId) + Number(delta || 0);

        if (next > 0) {
            state.processingRuns[runId] = next;
            return;
        }

        delete state.processingRuns[runId];
    }

    function clearLocalWorkerCount(runId) {
        if (!runId) {
            return;
        }

        delete state.processingRuns[runId];
    }

    function resetRunLocalState(runId) {
        if (!runId) {
            return;
        }

        clearRunLocallyStopped(runId);
        clearLocalWorkerCount(runId);
        abortRunProcessRequests(runId);
    }

    function rememberProcessController(runId, controller) {
        if (!runId || !controller) {
            return;
        }

        if (!state.processControllers[runId]) {
            state.processControllers[runId] = [];
        }

        state.processControllers[runId].push(controller);
    }

    function forgetProcessController(runId, controller) {
        if (!runId || !controller || !state.processControllers[runId]) {
            return;
        }

        state.processControllers[runId] = state.processControllers[runId].filter(function (item) {
            return item !== controller;
        });

        if (!state.processControllers[runId].length) {
            delete state.processControllers[runId];
        }
    }

    function abortRunProcessRequests(runId) {
        var controllers;

        if (!runId || !state.processControllers[runId]) {
            return;
        }

        controllers = state.processControllers[runId].slice();
        delete state.processControllers[runId];

        controllers.forEach(function (controller) {
            try {
                controller.abort();
            } catch (error) {
            }
        });
    }

    function isRunLocallyStopped(runId) {
        return !!(runId && state.blockedRuns[runId]);
    }

    function clearRunLocallyStopped(runId) {
        if (!runId) {
            return;
        }

        delete state.blockedRuns[runId];
    }

    function markRunLocallyStopped(runId, status) {
        var nextStatus = String(status || 'paused');

        if (!runId) {
            return;
        }

        state.blockedRuns[runId] = nextStatus;
        abortRunProcessRequests(runId);
        clearLocalWorkerCount(runId);

        if (state.activeRunId === runId) {
            state.activeRunStatus = nextStatus;

            if (state.activeRunDetail) {
                state.activeRunDetail.status = nextStatus;
                state.activeRunDetail.next_scheduled_at = null;

                if (state.activeRunDetail.summary) {
                    state.activeRunDetail.summary.next_scheduled_at = null;
                }
            }
        }

        if (state.lastRuns && state.lastRuns.length) {
            renderRuns(state.lastRuns);
        }

        if (state.activeRunDetail) {
            renderRunDetail(state.activeRunDetail);
        }

        stopPolling();
    }

    function applyLocalRunOverride(run) {
        var stoppedStatus;
        var nextRun;

        if (!run || !run.run_id || !isRunLocallyStopped(run.run_id)) {
            return run;
        }

        stoppedStatus = state.blockedRuns[run.run_id];

        if (!isProcessableStatus(run.status) && run.status !== stoppedStatus) {
            return run;
        }

        nextRun = Object.assign({}, run, {
            status: stoppedStatus,
            next_scheduled_at: null,
            worker_message: stoppedStatus === 'cancelled'
                ? '取消请求已发送，正在等待后台 worker 在安全检查点退出。'
                : '暂停请求已发送，正在等待后台 worker 在安全检查点退出。'
        });

        nextRun.summary = Object.assign({}, run.summary || {}, {
            next_scheduled_at: null
        });

        return nextRun;
    }

    function shouldMaintainRun(runId, status) {
        return !!runId && isProcessableStatus(status) && !isRunLocallyStopped(runId);
    }

    function padDateTimePart(value) {
        var number = Number(value || 0);

        if (number < 10) {
            return '0' + number;
        }

        return String(number);
    }

    function parseUtcDate(value) {
        var timestamp;

        if (!value) {
            return null;
        }

        timestamp = parseUtcTimestamp(value);

        if (!timestamp) {
            return null;
        }

        return new Date(timestamp);
    }

    function formatBeijingDateTime(value) {
        var date = parseUtcDate(value);
        var beijingTimestamp;
        var beijingDate;

        if (!date) {
            return '';
        }

        beijingTimestamp = date.getTime() + (8 * 60 * 60 * 1000);
        beijingDate = new Date(beijingTimestamp);

        return [
            beijingDate.getUTCFullYear(),
            padDateTimePart(beijingDate.getUTCMonth() + 1),
            padDateTimePart(beijingDate.getUTCDate())
        ].join('-') + ' ' + [
            padDateTimePart(beijingDate.getUTCHours()),
            padDateTimePart(beijingDate.getUTCMinutes()),
            padDateTimePart(beijingDate.getUTCSeconds())
        ].join(':');
    }

    function formatDateTime(value) {
        var formatted = formatBeijingDateTime(value);

        return formatted ? escapeHtml(formatted) : '—';
    }

    function formatBoolean(value) {
        return value ? '是' : '否';
    }

    function parseUtcTimestamp(value) {
        if (!value) {
            return 0;
        }

        var normalized = String(value).trim();
        if (!normalized) {
            return 0;
        }

        return Date.parse(normalized.replace(' ', 'T') + 'Z') || 0;
    }

    function generateModelProfileId() {
        return 'model-' + Math.random().toString(16).slice(2, 10) + '-' + Date.now().toString(36);
    }

    function normalizeModelProfile(profile) {
        return {
            profile_id: profile && profile.profile_id ? String(profile.profile_id) : generateModelProfileId(),
            name: profile && profile.name ? String(profile.name) : '未命名模型',
            provider_type: 'openai-compatible',
            base_url: profile && profile.base_url ? String(profile.base_url) : '',
            api_key: profile && profile.api_key ? String(profile.api_key) : '',
            api_key_masked: profile && profile.api_key_masked ? String(profile.api_key_masked) : '',
            api_key_configured: !!(profile && profile.api_key_configured),
            model: profile && profile.model ? String(profile.model) : '',
            temperature: clampNumber(profile && profile.temperature, 0, 2, 0.2),
            max_tokens: clampNumber(profile && profile.max_tokens, 256, 16384, 3200),
            request_timeout: clampNumber(profile && profile.request_timeout, 5, 300, 60)
        };
    }

    function getModelProfilesFromSettings(settings) {
        var profiles = settings && Array.isArray(settings.model_profiles) ? settings.model_profiles : [];

        return profiles.map(normalizeModelProfile);
    }

    function syncModelProfilesInput() {
        var input = $('#aiditor-model-profiles-json');

        if (!input) {
            return;
        }

        input.value = JSON.stringify(state.modelProfiles.map(function (profile) {
            return {
                profile_id: profile.profile_id,
                name: profile.name,
                provider_type: 'openai-compatible',
                base_url: profile.base_url,
                api_key: profile.api_key || '',
                model: profile.model,
                temperature: profile.temperature,
                max_tokens: profile.max_tokens,
                request_timeout: profile.request_timeout
            };
        }));
    }

    function renderModelProfileOptions(selected) {
        var defaultSelect = $('#aiditor-default-model-profile');
        var runSelect = $('#aiditor-run-model-profile');
        var options = state.modelProfiles.map(function (profile) {
            var label = profile.name + '（' + profile.model + '）';
            return '<option value="' + escapeHtml(profile.profile_id) + '">' + escapeHtml(label) + '</option>';
        }).join('');

        if (defaultSelect) {
            defaultSelect.innerHTML = '<option value="">未设置默认模型</option>' + options;
            defaultSelect.value = selected !== undefined ? selected : '';

            if (!defaultSelect.value) {
                defaultSelect.value = '';
            }
        }

        if (runSelect) {
            runSelect.innerHTML = options || '<option value="">请先添加模型配置</option>';

            if (selected && state.modelProfiles.some(function (profile) {
                return profile.profile_id === selected;
            })) {
                runSelect.value = selected;
            }

            if (!runSelect.value && state.modelProfiles[0]) {
                runSelect.value = state.modelProfiles[0].profile_id;
            }
        }
    }

    function renderModelProfiles(selected) {
        var list = $('#aiditor-model-profile-list');
        var defaultId = selected !== undefined ? selected : ((getCurrentSettings() || {}).default_model_profile_id || '');

        renderModelProfileOptions(defaultId);
        syncModelProfilesInput();

        if (!list) {
            return;
        }

        if (!state.modelProfiles.length) {
            list.innerHTML = '<p class="description">暂无模型配置。</p>';
            return;
        }

        list.innerHTML = state.modelProfiles.map(function (profile) {
            var isDefault = ($('#aiditor-default-model-profile') && $('#aiditor-default-model-profile').value === profile.profile_id) || defaultId === profile.profile_id;
            var keyText = profile.api_key_configured || profile.api_key
                ? '密钥：' + (profile.api_key_masked || '已填写')
                : '密钥：未配置';

            return '<article class="aiditor-model-profile-card" data-model-profile-id="' + escapeHtml(profile.profile_id) + '">' +
                '<div class="aiditor-model-profile-main">' +
                    '<strong>' + escapeHtml(profile.name) + '</strong>' +
                    '<p class="description">' + escapeHtml(profile.model) + ' · ' + escapeHtml(profile.base_url) + '</p>' +
                    '<small>' + escapeHtml(keyText) + ' · 温度 ' + escapeHtml(profile.temperature) + ' · Tokens ' + escapeHtml(profile.max_tokens) + ' · 超时 ' + escapeHtml(profile.request_timeout) + ' 秒</small>' +
                '</div>' +
                '<div class="aiditor-template-card-actions">' +
                    (isDefault ? '<span class="aiditor-pill is-muted">默认</span>' : '') +
                    '<button type="button" class="button button-small" data-model-profile-action="edit" data-model-profile-id="' + escapeHtml(profile.profile_id) + '">编辑</button>' +
                    '<button type="button" class="button button-small" data-model-profile-action="delete" data-model-profile-id="' + escapeHtml(profile.profile_id) + '">删除</button>' +
                '</div>' +
            '</article>';
        }).join('');
    }

    function clearModelProfileEditor() {
        var fields = {
            '#aiditor-model-profile-id': '',
            '#aiditor-model-profile-name': '',
            '#aiditor-model-profile-base-url': '',
            '#aiditor-model-profile-api-key': '',
            '#aiditor-model-profile-model': '',
            '#aiditor-model-profile-temperature': '0.2',
            '#aiditor-model-profile-max-tokens': '3200',
            '#aiditor-model-profile-timeout': '60'
        };

        Object.keys(fields).forEach(function (selector) {
            var element = $(selector);
            if (element) {
                element.value = fields[selector];
            }
        });

        if ($('#aiditor-model-profile-key-hint')) {
            $('#aiditor-model-profile-key-hint').textContent = '新增模型时请填写 API 密钥；编辑已有模型时留空表示继续使用原密钥。';
        }
    }

    function loadModelProfileEditor(profile) {
        if (!profile) {
            clearModelProfileEditor();
            return;
        }

        if ($('#aiditor-model-profile-id')) {
            $('#aiditor-model-profile-id').value = profile.profile_id || '';
        }
        if ($('#aiditor-model-profile-name')) {
            $('#aiditor-model-profile-name').value = profile.name || '';
        }
        if ($('#aiditor-model-profile-base-url')) {
            $('#aiditor-model-profile-base-url').value = profile.base_url || '';
        }
        if ($('#aiditor-model-profile-api-key')) {
            $('#aiditor-model-profile-api-key').value = '';
        }
        if ($('#aiditor-model-profile-model')) {
            $('#aiditor-model-profile-model').value = profile.model || '';
        }
        if ($('#aiditor-model-profile-temperature')) {
            $('#aiditor-model-profile-temperature').value = profile.temperature;
        }
        if ($('#aiditor-model-profile-max-tokens')) {
            $('#aiditor-model-profile-max-tokens').value = profile.max_tokens;
        }
        if ($('#aiditor-model-profile-timeout')) {
            $('#aiditor-model-profile-timeout').value = profile.request_timeout;
        }
        if ($('#aiditor-model-profile-key-hint')) {
            $('#aiditor-model-profile-key-hint').textContent = profile.api_key_configured
                ? '已配置密钥：' + (profile.api_key_masked || '')
                : '当前模型尚未配置 API 密钥。';
        }
    }

    function readModelProfileEditor() {
        var profileId = $('#aiditor-model-profile-id') ? $('#aiditor-model-profile-id').value : '';
        var name = $('#aiditor-model-profile-name') ? $('#aiditor-model-profile-name').value.trim() : '';
        var baseUrl = $('#aiditor-model-profile-base-url') ? $('#aiditor-model-profile-base-url').value.trim() : '';
        var apiKey = $('#aiditor-model-profile-api-key') ? $('#aiditor-model-profile-api-key').value.trim() : '';
        var model = $('#aiditor-model-profile-model') ? $('#aiditor-model-profile-model').value.trim() : '';

        if (!name) {
            throw new Error('请填写模型配置名称。');
        }

        if (!baseUrl) {
            throw new Error('请填写模型接口地址。');
        }

        if (!model) {
            throw new Error('请填写模型名称。');
        }

        return normalizeModelProfile({
            profile_id: profileId || generateModelProfileId(),
            name: name,
            base_url: baseUrl,
            api_key: apiKey,
            model: model,
            temperature: $('#aiditor-model-profile-temperature') ? $('#aiditor-model-profile-temperature').value : 0.2,
            max_tokens: $('#aiditor-model-profile-max-tokens') ? $('#aiditor-model-profile-max-tokens').value : 3200,
            request_timeout: $('#aiditor-model-profile-timeout') ? $('#aiditor-model-profile-timeout').value : 60
        });
    }

    function populateSettings(settings) {
        if (!settings) {
            return;
        }

        var fieldMap = {
            queue_batch_size: '#aiditor-queue-batch-size',
            queue_time_limit: '#aiditor-queue-time-limit',
            queue_concurrency: '#aiditor-queue-concurrency',
            queue_poll_interval: '#aiditor-queue-poll-interval',
            log_retention_days: '#aiditor-log-retention-days',
            default_post_status: '#aiditor-default-status',
            default_article_style: '#aiditor-default-style'
        };

        Object.keys(fieldMap).forEach(function (key) {
            var element = $(fieldMap[key]);
            if (element && settings[key] !== undefined) {
                element.value = settings[key];
            }
        });

        state.modelProfiles = getModelProfilesFromSettings(settings);
        renderModelProfiles(settings.default_model_profile_id || '');
        clearModelProfileEditor();

        renderArticleStyleOptions(state.articleStyles, settings.default_article_style || 'editorial-guide');
    }

    function buildSettingsPayload(form) {
        var payload = {};
        var formData;

        if (!form) {
            throw new Error('未找到设置表单。');
        }

        syncModelProfilesInput();
        formData = new FormData(form);
        formData.forEach(function (value, key) {
            payload[key] = value;
        });

        return payload;
    }

    function applySavedSettings(data) {
        getAppConfig().settings = data.settings || {};
        populateSettings(data.settings || {});
        renderRunDetail(state.activeRunDetail);

        if (state.activeRunId && isProcessableStatus(state.activeRunStatus)) {
            ensurePolling();
        } else {
            stopPolling();
        }
    }

    function saveSettingsForm(form, notice, pendingMessage, successMessage) {
        var payload;

        try {
            payload = buildSettingsPayload(form);
        } catch (error) {
            setNotice(notice, error.message, 'error');
            return Promise.reject(error);
        }

        setNotice(notice, pendingMessage || '正在保存设置…', 'success');

        return api('settings', {
            method: 'POST',
            body: JSON.stringify(payload)
        }).then(function (data) {
            applySavedSettings(data);
            setNotice(notice, successMessage || '设置已保存。', 'success');
            return data;
        }).catch(function (error) {
            setNotice(notice, error.message, 'error');
            throw error;
        });
    }

    function renderArticleStyleOptions(styles, selected) {
        var select = $('#aiditor-default-style');

        if (!select) {
            return;
        }

        select.innerHTML = (styles || []).map(function (style) {
            return '<option value="' + escapeHtml(style.style_id || '') + '">' + escapeHtml(style.name || style.style_id || '未命名风格') + '</option>';
        }).join('');

        if (selected) {
            select.value = selected;
        }
    }

    function renderArticleStyles(styles) {
        var list = $('#aiditor-style-list');

        state.articleStyles = styles || [];
        renderArticleStyleOptions(state.articleStyles, (getCurrentSettings() || {}).default_article_style || 'editorial-guide');

        if (!list) {
            return;
        }

        if (!state.articleStyles.length) {
            list.innerHTML = '<p class="description">暂无文章风格。</p>';
            return;
        }

        list.innerHTML = state.articleStyles.map(function (style) {
            var builtin = !!style.is_builtin || ['editorial-guide', 'news-brief', 'deep-analysis', 'product-review', 'practical-guide', 'popular-science', 'industry-observation', 'seo-evergreen', 'human-column'].indexOf(style.style_id || '') !== -1;
            var deletable = !builtin;
            var description = style.description || '未填写说明。';
            var prompt = style.prompt || '暂无提示词内容。';

            return '<article class="aiditor-style-card" data-style-id="' + escapeHtml(style.style_id || '') + '">' +
                '<div class="aiditor-style-card-header">' +
                    '<div class="aiditor-style-card-title"><strong>' + escapeHtml(style.name || '未命名风格') + '</strong><p class="description">' + escapeHtml(description) + '</p></div>' +
                    '<div class="aiditor-actions"><button type="button" class="button button-small" data-style-action="edit" data-style-id="' + escapeHtml(style.style_id || '') + '">编辑</button>' +
                    (deletable ? '<button type="button" class="button button-small" data-style-action="delete" data-style-id="' + escapeHtml(style.style_id || '') + '">删除</button>' : '<span class="aiditor-pill is-muted">内置风格</span>') + '</div>' +
                '</div>' +
                '<div class="aiditor-prompt-snippet">' + escapeHtml(prompt) + '</div>' +
            '</article>';
        }).join('');
    }

    function refreshArticleStyles() {
        return api('article-styles').then(function (data) {
            renderArticleStyles(data.styles || []);
            return data;
        });
    }

    function renderPreview(items, meta) {
        var container = $('#aiditor-preview-results');
        var description;

        if (!container) {
            return;
        }

        if (!items || !items.length) {
            container.innerHTML = '<p class="description">这个列表页没有找到可导入的候选内容。</p>';
            return;
        }

        description = '<p class="description">当前展示 ' + escapeHtml(meta.preview_limit || items.length) +
            ' 条预览数据，采集数量设置为 ' + escapeHtml(meta.requested_limit || items.length) + ' 条。</p>';

        var rows = items.map(function (item) {
            var duplicate = item.is_duplicate
                ? '<span class="aiditor-pill is-danger">已存在</span>'
                : '<span class="aiditor-pill">新内容</span>';

            return '<tr>' +
                '<td><strong>' + escapeHtml(item.title) + '</strong><br /><code>' + escapeHtml(item.slug) + '</code></td>' +
                '<td>' + duplicate + '</td>' +
                '<td>' + escapeHtml(item.version || '-') + '</td>' +
                '<td>' + escapeHtml(item.summary_zh || item.summary || '-') + '</td>' +
                '</tr>';
        }).join('');

        container.innerHTML = description + '<table><thead><tr><th>条目</th><th>状态</th><th>版本</th><th>摘要</th></tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function getShortRunId(runId) {
        var value = String(runId || '');

        if (value.length <= 13) {
            return value || '-';
        }

        return value.slice(0, 8) + '…' + value.slice(-4);
    }

    function renderRunStat(label, value) {
        return '<span class="aiditor-run-stat">' +
            '<small class="aiditor-run-stat-label">' + escapeHtml(label) + '</small>' +
            '<b class="aiditor-run-stat-value">' + escapeHtml(String(Number(value || 0))) + '</b>' +
        '</span>';
    }

    function renderRunStats(run) {
        var summary = run.summary || {};

        return [
            renderRunStat('请求', summary.requested || run.requested_limit || run.limit || 0),
            renderRunStat('已处理', summary.processed || 0),
            renderRunStat('待处理', summary.pending || 0),
            renderRunStat('成功', summary.created || 0),
            renderRunStat('更新', summary.updated || 0),
            renderRunStat('失败', summary.failed || 0)
        ].join('');
    }

    function canDeleteRun(run) {
        var status = String((run && run.status) || '');
        return ['paused', 'completed', 'completed_with_errors', 'failed', 'cancelled'].indexOf(status) !== -1;
    }

    function buildRunCardHelper(run) {
        var summary = (run && run.summary) || {};
        var failed = Number(summary.failed || 0);
        var created = Number(summary.created || 0);
        var updated = Number(summary.updated || 0);
        var skipped = Number(summary.skipped || 0);
        var status = String((run && run.status) || '');

        if (run && run.worker_message) {
            return String(run.worker_message);
        }

        if (status === 'completed_with_errors') {
            return failed > 0 ? '任务已完成，存在失败条目。' : '任务已完成，但需要人工复核。';
        }

        if (status === 'completed') {
            return '任务已完成。';
        }

        if (status === 'cancelled') {
            return '任务已取消。';
        }

        if (status === 'paused') {
            return '任务已暂停，可继续恢复或直接删除记录。';
        }

        if (status === 'failed') {
            return '任务执行失败，可检查错误后重试或删除记录。';
        }

        return '成功 ' + created + '，更新 ' + updated + '，跳过 ' + skipped + '，失败 ' + failed + '。';
    }

    function renderRunActions(run) {
        var runId = escapeHtml(run.run_id || '');
        var status = String(run.status || '');
        var summary = run.summary || {};
        var failedCount = Number(summary.failed || 0);
        var actions = [];
        var deleteAction = '';

        if (isProcessableStatus(status)) {
            actions.push('<button type="button" class="button button-small" data-run-action="process" data-run-id="' + runId + '">立即处理</button>');
            actions.push('<button type="button" class="button button-small" data-run-action="pause" data-run-id="' + runId + '">暂停</button>');
            actions.push('<button type="button" class="button button-small" data-run-action="cancel" data-run-id="' + runId + '">取消</button>');
        } else if ('paused' === status) {
            actions.push('<button type="button" class="button button-small button-primary" data-run-action="resume" data-run-id="' + runId + '">恢复</button>');
            actions.push('<button type="button" class="button button-small" data-run-action="cancel" data-run-id="' + runId + '">取消</button>');
        } else {
            if (failedCount > 0 && 'cancelled' !== status) {
                actions.push('<button type="button" class="button button-small button-primary" data-run-action="retry-failed" data-run-id="' + runId + '">重新采集失败条目</button>');
            }
        }

        if (canDeleteRun(run)) {
            deleteAction = '<button type="button" class="button-link-delete aiditor-run-delete" data-run-action="delete" data-run-id="' + runId + '">删除记录</button>';
        }

        if (!actions.length) {
            actions.push('<span class="description">暂无可用操作</span>');
        }

        return '' +
            '<div class="aiditor-run-main-actions">' + actions.join(' ') + '</div>' +
            '<div class="aiditor-run-tail-action">' + deleteAction + '</div>';
    }

    function renderMetricCard(title, value, description) {
        return '<div class="aiditor-metric-card">' +
            '<div class="aiditor-metric-title">' + escapeHtml(title) + '</div>' +
            '<div class="aiditor-metric-value">' + escapeHtml(value) + '</div>' +
            (description ? '<div class="aiditor-metric-description">' + escapeHtml(description) + '</div>' : '') +
            '</div>';
    }

    function syncActiveRun(runs) {
        var matched = null;

        (runs || []).forEach(function (run) {
            if (run.run_id === state.activeRunId) {
                matched = run;
            }
        });

        if (!matched) {
            matched = (runs || []).find(function (run) {
                return !isTerminalStatus(run.status) && 'cancelled' !== run.status;
            }) || ((runs || [])[0] || null);
        }

        if (state.activeRunId !== (matched ? matched.run_id : null)) {
            state.activeRunDetail = null;
        }

        state.activeRunId = matched ? matched.run_id : null;
        state.activeRunStatus = matched ? String(matched.status || '') : '';

        if (state.activeRunId && isProcessableStatus(state.activeRunStatus)) {
            ensurePolling();
        } else {
            stopPolling();
        }
    }

    function renderRuns(runs) {
        var container = $('#aiditor-run-results');

        if (!container) {
            return;
        }

        state.lastRuns = runs || [];
        runs = (runs || []).map(function (run) {
            return applyLocalRunOverride(run);
        });

        if (!runs || !runs.length) {
            container.innerHTML = '<p class="description">暂无导入任务。</p>';
            syncActiveRun([]);
            renderRunDetail(null);
            return;
        }

        syncActiveRun(runs);

        var cards = runs.map(function (run) {
            var statusClass = getStatusPillClass(run.status);
            var isActive = run.run_id === state.activeRunId;
            var helperText = buildRunCardHelper(run);
            var helper = '<div class="aiditor-run-helper" title="' + escapeHtml(helperText) + '">' + escapeHtml(helperText) + '</div>';

            return '<article class="aiditor-run-card' + (isActive ? ' is-active' : '') + '" data-run-id="' + escapeHtml(run.run_id || '') + '">' +
                '<div class="aiditor-run-card-top">' +
                    '<div class="aiditor-run-card-title">' +
                        '<strong title="' + escapeHtml(run.run_id || '-') + '">' + escapeHtml(getShortRunId(run.run_id || '-')) + '</strong>' +
                        '<span>' + formatDateTime(run.created_at) + '</span>' +
                    '</div>' +
                    '<span class="aiditor-pill ' + statusClass + '">' + escapeHtml(getRunStatusLabel(run.status || '-')) + '</span>' +
                '</div>' +
                '<div class="aiditor-run-card-stats">' + renderRunStats(run) + '</div>' +
                helper +
                '<div class="aiditor-run-card-actions">' + renderRunActions(run) + '</div>' +
            '</article>';
        }).join('');

        container.innerHTML = '<div class="aiditor-run-card-list">' + cards + '</div>';
    }

    function renderRunDetail(run) {
        var container = $('#aiditor-run-detail');
        var settings = getCurrentSettings();

        if (!container) {
            return;
        }

        if (!run) {
            container.innerHTML = '<p class="description">选择一个任务后，这里会显示批处理进度、调度状态以及最近条目明细。</p>';
            return;
        }

        var summary = run.summary || {};
        var runExtra = run.extra_tax_terms || {};
        var runModelLabel = runExtra._aiditor_model_profile_name
            ? runExtra._aiditor_model_profile_name + (runExtra._aiditor_model ? '（' + runExtra._aiditor_model + '）' : '')
            : (runExtra._aiditor_model || '—');
        var metrics = [
            renderMetricCard('请求数', summary.requested || run.requested_limit || run.limit || 0, '本次任务计划处理的条目数'),
            renderMetricCard('已发现', summary.discovered || 0, '当前已从来源列表中发现的条目数'),
            renderMetricCard('待处理', summary.pending || 0, '包括可立即执行和延迟重试的条目'),
            renderMetricCard('处理中', summary.running || 0, '当前正由 worker 占用的条目数'),
            renderMetricCard('成功', summary.created || 0, '已成功创建文章的条目数'),
            renderMetricCard('更新', summary.updated || 0, '已就地更新已有文章的条目数'),
            renderMetricCard('跳过', summary.skipped || 0, '因去重跳过的条目数'),
            renderMetricCard('失败', summary.failed || 0, '已标记失败的条目数'),
            renderMetricCard('取消', summary.cancelled || 0, '因任务取消而停止的条目数'),
            renderMetricCard('本轮处理', run.last_batch_count || summary.last_batch_count || 0, '最近一轮批处理实际推进的条目数')
        ].join('');

        var scheduleRows = [
            ['任务状态', getRunStatusLabel(run.status || '-')],
            ['AI 模型', runModelLabel],
            ['来源 URL', run.source_url || '—'],
            ['当前页码', run.current_page || 1],
            ['来源是否取尽', formatBoolean(run.source_exhausted)],
            ['可立即执行条目', summary.ready_pending || 0],
            ['延迟重试条目', summary.delayed_pending || 0],
            ['最近批次开始', formatDateTime(run.last_batch_started_at)],
            ['最近批次结束', formatDateTime(run.last_batch_finished_at)],
            ['最近一次调度', formatDateTime(run.last_scheduled_at)],
            ['下一次调度', formatDateTime(run.next_scheduled_at)],
            ['下一次 AI 重试', formatDateTime(summary.next_retry_at)],
            ['最近错误', run.last_error || '—'],
            ['当前说明', run.worker_message || '—']
        ].map(function (row) {
            return '<tr><th>' + escapeHtml(row[0]) + '</th><td>' + escapeHtml(row[1]) + '</td></tr>';
        }).join('');

        var queueRows = [
            ['单个 worker 单轮最大处理条数', settings.queue_batch_size || 10],
            ['单个 worker 单轮最大执行秒数', settings.queue_time_limit || 40],
            ['并发处理数', settings.queue_concurrency || 4],
            ['本页活动 worker', getLocalWorkerCount(run.run_id || state.activeRunId || '')],
            ['后台轮询间隔', (settings.queue_poll_interval || 3) + ' 秒']
        ].map(function (row) {
            return '<tr><th>' + escapeHtml(row[0]) + '</th><td>' + escapeHtml(row[1]) + '</td></tr>';
        }).join('');

        var items = Array.isArray(run.items) ? run.items : [];
        var failedItems = Array.isArray(run.failed_items) ? run.failed_items : [];
        var itemRows = items.length
            ? items.map(function (item) {
                return '<tr>' +
                    '<td>' + escapeHtml((Number(item.item_index || 0) + 1)) + '</td>' +
                    '<td><strong>' + escapeHtml(item.title || item.slug || '-') + '</strong><br /><code>' + escapeHtml(item.slug || '-') + '</code></td>' +
                    '<td><span class="aiditor-pill ' + getStatusPillClass(item.status) + '">' + escapeHtml(getItemStatusLabel(item.status)) + '</span></td>' +
                    '<td>' + escapeHtml(item.attempt_count || 0) + ' / ' + escapeHtml(item.retry_count || 0) + '</td>' +
                    '<td>' + formatDateTime(item.processed_at) + '</td>' +
                    '<td>' + escapeHtml(item.post_id || '—') + '</td>' +
                    '<td>' + escapeHtml(item.message || '—') + '</td>' +
                    '</tr>';
            }).join('')
            : '<tr><td colspan="7" class="aiditor-empty-cell">当前任务还没有条目明细。</td></tr>';
        var failedRows = failedItems.length
            ? failedItems.map(function (item) {
                return '<tr>' +
                    '<td>' + escapeHtml((Number(item.item_index || 0) + 1)) + '</td>' +
                    '<td><strong>' + escapeHtml(item.title || item.slug || '-') + '</strong><br /><code>' + escapeHtml(item.slug || '-') + '</code></td>' +
                    '<td>' + escapeHtml(item.attempt_count || 0) + ' / ' + escapeHtml(item.retry_count || 0) + '</td>' +
                    '<td>' + formatDateTime(item.processed_at) + '</td>' +
                    '<td>' + escapeHtml(item.message || item.last_retry_error || '—') + '</td>' +
                    '</tr>';
            }).join('')
            : '<tr><td colspan="5" class="aiditor-empty-cell">当前任务没有失败条目。</td></tr>';
        var failedPanel = (failedItems.length || Number(summary.failed || 0) > 0)
            ? '<div class="aiditor-detail-panel">' +
                '<div class="aiditor-detail-panel-header"><h3>失败条目列表</h3><button type="button" class="button button-small button-primary" data-run-action="retry-failed" data-run-id="' + escapeHtml(run.run_id || '') + '">重新采集失败条目</button></div>' +
                '<p class="description">这里最多显示最近 100 条失败条目。点击重新采集后，会把这些失败条目重新加入当前任务队列。</p>' +
                '<table class="aiditor-detail-items"><thead><tr><th>#</th><th>条目</th><th>尝试 / 重试</th><th>失败时间（北京时间）</th><th>错误说明</th></tr></thead><tbody>' + failedRows + '</tbody></table>' +
            '</div>'
            : '';

        container.innerHTML = '' +
            '<div class="aiditor-detail-header">' +
                '<div>' +
                    '<div class="aiditor-detail-kicker">任务编号</div>' +
                    '<div class="aiditor-detail-run-id"><code>' + escapeHtml(run.run_id || '-') + '</code></div>' +
                '</div>' +
                '<div class="aiditor-detail-actions">' + renderRunActions(run) + '</div>' +
            '</div>' +
            '<div class="aiditor-detail-metrics">' + metrics + '</div>' +
            '<div class="aiditor-detail-grid">' +
                '<div class="aiditor-detail-panel">' +
                    '<h3>任务总览</h3>' +
                    '<table class="aiditor-detail-table"><tbody>' + scheduleRows + '</tbody></table>' +
                '</div>' +
                '<div class="aiditor-detail-panel">' +
                    '<h3>吞吐与调度</h3>' +
                    '<table class="aiditor-detail-table"><tbody>' + queueRows + '</tbody></table>' +
                '</div>' +
            '</div>' +
            failedPanel +
            '<div class="aiditor-detail-panel">' +
                '<h3>最近条目明细</h3>' +
                '<table class="aiditor-detail-items"><thead><tr><th>#</th><th>条目</th><th>状态</th><th>尝试 / 重试</th><th>完成时间（北京时间）</th><th>文章 ID</th><th>说明</th></tr></thead><tbody>' + itemRows + '</tbody></table>' +
            '</div>';
    }

    function refreshRuns() {
        return api('runs?limit=100').then(function (data) {
            state.lastRuns = data.runs || [];
            renderRuns(data.runs || []);
            return data.runs || [];
        });
    }

    function refreshActiveRunDetail() {
        if (!state.activeRunId) {
            state.activeRunDetail = null;
            renderRunDetail(null);
            return Promise.resolve(null);
        }

        return api('runs/' + encodeURIComponent(state.activeRunId)).then(function (data) {
            state.activeRunDetail = applyLocalRunOverride(data.run || null);

            if (state.activeRunDetail) {
                state.activeRunStatus = String(state.activeRunDetail.status || '');
            }

            renderRunDetail(state.activeRunDetail);

            if (shouldMaintainRun(state.activeRunId, state.activeRunStatus)) {
                ensurePolling();
            } else {
                if (state.activeRunId && !isProcessableStatus(state.activeRunStatus)) {
                    clearLocalWorkerCount(state.activeRunId);
                }
                stopPolling();
            }

            return state.activeRunDetail;
        }).catch(function (error) {
            state.activeRunDetail = null;
            renderRunDetail(null);
            return null;
        });
    }

    function refreshRunViews() {
        return refreshRuns().then(function () {
            return refreshActiveRunDetail();
        });
    }

    function processRunWorker(runId) {
        var controller = window.AbortController ? new window.AbortController() : null;
        var requestOptions = {
            method: 'POST',
            body: JSON.stringify({})
        };

        if (!runId) {
            return Promise.resolve(null);
        }

        if (controller) {
            requestOptions.signal = controller.signal;
            rememberProcessController(runId, controller);
        }

        changeLocalWorkerCount(runId, 1);

        return api('runs/' + encodeURIComponent(runId) + '/process', requestOptions).then(function (data) {
            if (data && data.run && state.activeRunId === runId && !isRunLocallyStopped(runId)) {
                state.activeRunStatus = String(data.run.status || '');
                state.activeRunDetail = data.run;
            }

            return refreshRunViews();
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return null;
            }

            return refreshRunViews();
        }).finally(function () {
            forgetProcessController(runId, controller);
            changeLocalWorkerCount(runId, -1);
        });
    }

    function maintainWorkerPool(detail) {
        var runId = state.activeRunId;
        var currentDetail = detail || state.activeRunDetail || {};
        var summary = currentDetail.summary || {};
        var readyPending = Number(summary.ready_pending || 0);
        var nextScheduledAt = parseUtcTimestamp(currentDetail.next_scheduled_at || summary.next_scheduled_at || '');
        var localWorkers = getLocalWorkerCount(runId);

        if (!runId || !shouldMaintainRun(runId, state.activeRunStatus)) {
            return Promise.resolve(null);
        }

        if (localWorkers > 0) {
            return Promise.resolve(currentDetail);
        }

        if (readyPending < 1 && nextScheduledAt && nextScheduledAt > Date.now()) {
            return Promise.resolve(currentDetail);
        }

        return processRunWorker(runId);
    }

    function processActiveRun() {
        if (!shouldMaintainRun(state.activeRunId, state.activeRunStatus)) {
            return Promise.resolve(null);
        }

        return refreshActiveRunDetail().then(function (detail) {
            return maintainWorkerPool(detail);
        });
    }

    function pollActiveRun() {
        if (!state.activeRunId) {
            return Promise.resolve(null);
        }

        return refreshActiveRunDetail();
    }

    function ensurePolling() {
        var interval = getPollIntervalMs();

        if (state.pollTimer && state.pollIntervalMs === interval) {
            return;
        }

        stopPolling();
        state.pollIntervalMs = interval;
        state.pollTimer = window.setInterval(function () {
            pollActiveRun();
        }, interval);
    }

    function stopPolling() {
        if (!state.pollTimer) {
            return;
        }

        window.clearInterval(state.pollTimer);
        state.pollTimer = null;
        state.pollIntervalMs = 0;
    }

    function initTabs() {
        var tabs = document.querySelectorAll('[data-tab-target]');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (button) {
                    button.classList.remove('nav-tab-active');
                });
                tab.classList.add('nav-tab-active');

                document.querySelectorAll('[data-tab-panel]').forEach(function (panel) {
                    var active = panel.getAttribute('data-tab-panel') === tab.getAttribute('data-tab-target');
                    panel.hidden = !active;
                    panel.classList.toggle('is-active', active);
                });
            });
        });
    }

    function renderPostTypeOptions(postTypes) {
        var select = $('#aiditor-post-type');
        if (!select) {
            return;
        }

        select.innerHTML = (postTypes || []).map(function (postType) {
            return '<option value="' + escapeHtml(postType.name) + '">' + escapeHtml(postType.label) + '</option>';
        }).join('');
    }

    function renderTargetTaxonomies(targetTaxonomies) {
        var select = $('#aiditor-target-taxonomy');
        if (!select) {
            return;
        }

        select.innerHTML = (targetTaxonomies || []).map(function (taxonomy) {
            return '<option value="' + escapeHtml(taxonomy.name) + '">' + escapeHtml(taxonomy.label) + '</option>';
        }).join('');
    }

    function renderExtraTaxonomies(extraTaxonomies) {
        var container = $('#aiditor-extra-taxonomy-fields');
        if (!container) {
            return;
        }

        if (!extraTaxonomies || !extraTaxonomies.length) {
            container.innerHTML = '<p class="description">当前文章类型没有可选的附加分类法。</p>';
            return;
        }

        container.innerHTML = extraTaxonomies.map(function (taxonomy) {
            var options = (taxonomy.terms || []).map(function (term) {
                return '<option value="' + escapeHtml(term.term_id) + '">' + escapeHtml(term.name) + '</option>';
            }).join('');

            return '<div class="aiditor-extra-taxonomy">' +
                '<label for="aiditor-extra-' + escapeHtml(taxonomy.name) + '">' + escapeHtml(taxonomy.label) + '</label>' +
                '<select id="aiditor-extra-' + escapeHtml(taxonomy.name) + '" multiple data-extra-taxonomy="' + escapeHtml(taxonomy.name) + '">' +
                options +
                '</select>' +
                '</div>';
        }).join('');
    }

    function getSelectedTargetTaxonomy() {
        var select = $('#aiditor-target-taxonomy');
        return select ? select.value : '';
    }

    function findTargetTaxonomy(name) {
        var taxonomies = state.targetConfig && state.targetConfig.target_taxonomies
            ? state.targetConfig.target_taxonomies
            : [];

        return taxonomies.find(function (taxonomy) {
            return taxonomy.name === name;
        }) || null;
    }

    function renderRootTermLevel(taxonomy) {
        var container = $('#aiditor-term-levels');
        if (!container) {
            return;
        }

        if (!taxonomy || !taxonomy.root_terms || !taxonomy.root_terms.length) {
            container.innerHTML = '<p class="description">当前所选分类法下没有可选的顶级目录。</p>';
            return;
        }

        container.innerHTML = '';
        appendTermLevel(container, taxonomy.name, taxonomy.root_terms, 0);
    }

    function appendTermLevel(container, taxonomyName, terms, levelIndex) {
        var wrapper = document.createElement('div');
        wrapper.className = 'aiditor-term-level';
        wrapper.setAttribute('data-level-index', String(levelIndex));

        var select = document.createElement('select');
        select.innerHTML = '<option value="">请选择…</option>' + (terms || []).map(function (term) {
            return '<option value="' + escapeHtml(term.term_id) + '" data-has-children="' + (term.has_children ? '1' : '0') + '">' + escapeHtml(term.name) + '</option>';
        }).join('');

        select.addEventListener('change', function () {
            trimTermLevels(levelIndex);

            if (!select.value) {
                return;
            }

            var selectedOption = select.options[select.selectedIndex];
            var hasChildren = selectedOption && selectedOption.getAttribute('data-has-children') === '1';

            if (!hasChildren) {
                return;
            }

            api('terms?taxonomy=' + encodeURIComponent(taxonomyName) + '&parent=' + encodeURIComponent(select.value))
                .then(function (data) {
                    if (data.terms && data.terms.length) {
                        appendTermLevel(container, taxonomyName, data.terms, levelIndex + 1);
                    }
                });
        });

        wrapper.appendChild(select);
        container.appendChild(wrapper);
    }

    function trimTermLevels(levelIndex) {
        $all('.aiditor-term-level', $('#aiditor-term-levels')).forEach(function (level) {
            if (Number(level.getAttribute('data-level-index')) > levelIndex) {
                level.remove();
            }
        });
    }

    function getSelectedTargetTermId() {
        var selects = $all('.aiditor-term-level select', $('#aiditor-term-levels'));
        var selected = 0;

        selects.forEach(function (select) {
            if (select.value) {
                selected = Number(select.value);
            }
        });

        return selected;
    }

    function loadTargetConfiguration(postType) {
        return api('targets?post_type=' + encodeURIComponent(postType)).then(function (data) {
            state.targetConfig = data.targets || null;

            renderPostTypeOptions((state.targetConfig && state.targetConfig.post_types) || []);
            $('#aiditor-post-type').value = data.post_type;

            renderTargetTaxonomies((state.targetConfig && state.targetConfig.target_taxonomies) || []);
            renderExtraTaxonomies((state.targetConfig && state.targetConfig.extra_taxonomies) || []);

            var selectedTaxonomy = getSelectedTargetTaxonomy();
            var taxonomy = findTargetTaxonomy(selectedTaxonomy) || (((state.targetConfig && state.targetConfig.target_taxonomies) || [])[0] || null);

            if (taxonomy) {
                $('#aiditor-target-taxonomy').value = taxonomy.name;
            }

            renderRootTermLevel(taxonomy);
        });
    }

    function buildImportPayload(form) {
        var payload = {};
        var formData = new FormData(form);

        formData.forEach(function (value, key) {
            payload[key] = value;
        });

        payload.limit = Number(payload.limit || 20);
        payload.template_id = $('#aiditor-template-select') ? $('#aiditor-template-select').value : (payload.template_id || '');
        payload.model_profile_id = $('#aiditor-run-model-profile') ? $('#aiditor-run-model-profile').value : (payload.model_profile_id || '');
        payload.post_type = $('#aiditor-post-type') ? $('#aiditor-post-type').value : 'post';
        payload.target_taxonomy = getSelectedTargetTaxonomy();
        payload.target_term_id = getSelectedTargetTermId();
        payload.extra_tax_terms = {};

        $all('[data-extra-taxonomy]').forEach(function (select) {
            var selected = Array.prototype.slice.call(select.selectedOptions || []).map(function (option) {
                return Number(option.value);
            }).filter(function (value) {
                return value > 0;
            });

            if (selected.length) {
                payload.extra_tax_terms[select.getAttribute('data-extra-taxonomy')] = selected;
            }
        });

        return payload;
    }

    function validateImportPayload(payload) {
        if (!payload.source_url) {
            return '必须填写来源 URL。';
        }

        if (!payload.template_id) {
            return '请选择采集模板。';
        }

        if (!payload.model_profile_id) {
            return '请选择 AI 模型。';
        }

        if (!payload.target_taxonomy) {
            return '请选择目标分类法。';
        }

        if (!payload.target_term_id) {
            return '请选择最终入库目录。';
        }

        return '';
    }

    function runAction(runId, action, notice) {
        var nextStoppedStatus = '';
        var requestPath = 'runs/' + encodeURIComponent(runId) + '/' + action;
        var requestOptions = {
            method: 'POST',
            body: JSON.stringify({})
        };

        if ('delete' === action) {
            if (!window.confirm('将永久删除这条任务记录、条目明细和运行时锁，但不会删除已经入库的文章。确定继续吗？')) {
                return Promise.resolve(null);
            }

            requestPath = 'runs/' + encodeURIComponent(runId);
            requestOptions = {
                method: 'DELETE'
            };
        }

        if ('pause' === action) {
            nextStoppedStatus = 'paused';
        } else if ('cancel' === action) {
            nextStoppedStatus = 'cancelled';
        }

        state.activeRunId = runId;

        if (nextStoppedStatus) {
            markRunLocallyStopped(runId, nextStoppedStatus);
        } else {
            clearRunLocallyStopped(runId);
        }

        return api(requestPath, requestOptions).then(function () {
            if ('delete' === action) {
                resetRunLocalState(runId);

                if (state.activeRunId === runId) {
                    state.activeRunId = null;
                    state.activeRunStatus = '';
                    state.activeRunDetail = null;
                }
            }

            if ('resume' === action || 'process' === action || 'retry-failed' === action) {
                state.activeRunStatus = 'running';
            }

            return refreshRunViews();
        }).then(function () {
            setNotice(notice, '任务操作已完成：' + getRunActionLabel(action) + '。', 'success');
        }).catch(function (error) {
            if (nextStoppedStatus) {
                clearRunLocallyStopped(runId);
            }

            return refreshRunViews().catch(function () {
                return null;
            }).then(function () {
                setNotice(notice, error.message, 'error');
            });
        });
    }

    function initImport() {
        var form = $('#aiditor-import-form');
        var previewButton = $('#aiditor-preview-button');
        var runButton = $('#aiditor-run-button');
        var notice = $('#aiditor-import-notice');
        var postTypeSelect = $('#aiditor-post-type');
        var taxonomySelect = $('#aiditor-target-taxonomy');
        var runResults = $('#aiditor-run-results');
        var runDetail = $('#aiditor-run-detail');
        var refreshRunsButton = $('#aiditor-refresh-runs');
        var importDrawer = $('#aiditor-import-drawer');
        var previewDrawer = $('#aiditor-preview-drawer');

        if (!form || !previewButton || !runButton || !postTypeSelect || !taxonomySelect) {
            return;
        }

        postTypeSelect.addEventListener('change', function () {
            loadTargetConfiguration(postTypeSelect.value).catch(function (error) {
                setNotice(notice, error.message, 'error');
            });
        });

        taxonomySelect.addEventListener('change', function () {
            renderRootTermLevel(findTargetTaxonomy(taxonomySelect.value));
        });

        previewButton.addEventListener('click', function () {
            var payload = buildImportPayload(form);
            var validationError = validateImportPayload(payload);

            if (validationError) {
                setNotice(notice, validationError, 'error');
                return;
            }

            setNotice(notice, '正在使用模板识别详情页…', 'success');
            api('generic/discover-urls', {
                method: 'POST',
                body: JSON.stringify({url: payload.source_url, requirement: '请按所选采集模板识别详情页 URL。', limit: payload.limit})
            }).then(function (data) {
                renderPreview(((data.result || {}).items || []).map(function (item) {
                    return {title: item.title || item.url, source_url: item.url, summary: item.reason || '', slug: item.url};
                }), data || {});
                if (previewDrawer) {
                    previewDrawer.open = true;
                }
                setNotice(notice, '预览完成。', 'success');
            }).catch(function (error) {
                setNotice(notice, error.message, 'error');
            });
        });

        runButton.addEventListener('click', function () {
            var payload = buildImportPayload(form);
            var validationError = validateImportPayload(payload);

            if (validationError) {
                setNotice(notice, validationError, 'error');
                return;
            }

            setNotice(notice, '正在创建队列任务…', 'success');
            api('templates/' + encodeURIComponent(payload.template_id) + '/run', {
                method: 'POST',
                body: JSON.stringify(payload)
            }).then(function (data) {
                if (data && data.run && data.run.run_id) {
                    state.activeRunId = data.run.run_id;
                    state.activeRunStatus = String(data.run.status || 'queued');
                }

                if (importDrawer) {
                    importDrawer.open = false;
                }
                if (previewDrawer) {
                    previewDrawer.open = false;
                }
                setNotice(notice, '队列任务已创建，已收起创建区。', 'success');
                return refreshRunViews();
            }).catch(function (error) {
                setNotice(notice, error.message, 'error');
            });
        });

        if (refreshRunsButton) {
            refreshRunsButton.addEventListener('click', function () {
                setNotice(notice, '正在刷新任务列表…', 'success');
                refreshRunViews().then(function () {
                    setNotice(notice, '任务列表已刷新。', 'success');
                }).catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        if (runResults) {
            runResults.addEventListener('click', function (event) {
                var button = event.target.closest('[data-run-action]');
                var row;

                if (button) {
                    runAction(button.getAttribute('data-run-id'), button.getAttribute('data-run-action'), notice);
                    return;
                }

                row = event.target.closest('[data-run-id]');
                if (!row) {
                    return;
                }

                state.activeRunId = row.getAttribute('data-run-id');
                refreshActiveRunDetail().catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        if (runDetail) {
            runDetail.addEventListener('click', function (event) {
                var button = event.target.closest('[data-run-action]');

                if (!button) {
                    return;
                }

                runAction(button.getAttribute('data-run-id'), button.getAttribute('data-run-action'), notice);
            });
        }

        loadTargetConfiguration('post').catch(function (error) {
            setNotice(notice, error.message, 'error');
        });
    }

    function initSettings() {
        var form = $('#aiditor-settings-form');
        var notice = $('#aiditor-settings-notice');

        $all('[data-settings-tab]').forEach(function (button) {
            button.addEventListener('click', function () {
                var tab = button.getAttribute('data-settings-tab');

                $all('[data-settings-tab]').forEach(function (item) {
                    item.classList.toggle('is-active', item === button);
                });

                $all('[data-settings-panel]').forEach(function (panel) {
                    var active = panel.getAttribute('data-settings-panel') === tab;
                    panel.hidden = !active;
                    panel.classList.toggle('is-active', active);
                });

                var saveRow = $('.aiditor-settings-save-row');
                if (saveRow) {
                    saveRow.classList.toggle('is-hidden', tab === 'styles');
                }
            });
        });

        refreshArticleStyles().catch(function () {});
        initArticleStyleManager(notice);
        initModelProfileManager(notice);

        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            saveSettingsForm(form, notice, '正在保存设置…', '设置已保存。').catch(function () {});
        });
    }

    function initArticleStyleManager(notice) {
        var generateButton = $('#aiditor-generate-style');
        var saveButton = $('#aiditor-save-style');
        var list = $('#aiditor-style-list');

        if (generateButton) {
            generateButton.addEventListener('click', function () {
                var name = $('#aiditor-style-name') ? $('#aiditor-style-name').value : '';
                var description = $('#aiditor-style-description') ? $('#aiditor-style-description').value : '';

                setNotice(notice, '正在调用 AI 生成文章风格…', 'success');
                api('article-styles/generate', {
                    method: 'POST',
                    body: JSON.stringify({name: name, description: description})
                }).then(function (data) {
                    if ($('#aiditor-style-name') && data.name) {
                        $('#aiditor-style-name').value = data.name;
                    }
                    if ($('#aiditor-style-description') && data.description) {
                        $('#aiditor-style-description').value = data.description;
                    }
                    if ($('#aiditor-style-prompt')) {
                        $('#aiditor-style-prompt').value = data.prompt || '';
                    }
                    setNotice(notice, '文章风格已生成，请确认后保存。', 'success');
                }).catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        if (saveButton) {
            saveButton.addEventListener('click', function () {
                var payload = {
                    name: $('#aiditor-style-name') ? $('#aiditor-style-name').value : '',
                    description: $('#aiditor-style-description') ? $('#aiditor-style-description').value : '',
                    prompt: $('#aiditor-style-prompt') ? $('#aiditor-style-prompt').value : ''
                };

                setNotice(notice, '正在保存文章风格…', 'success');
                api('article-styles', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                }).then(function (data) {
                    renderArticleStyles(data.styles || []);
                    setNotice(notice, '文章风格已保存，可在队列设置中选择。', 'success');
                }).catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        if (list) {
            list.addEventListener('click', function (event) {
                var button = event.target.closest('[data-style-action]');
                var styleId;
                var style;

                if (!button) {
                    return;
                }

                styleId = button.getAttribute('data-style-id');
                style = state.articleStyles.find(function (item) {
                    return item.style_id === styleId;
                });

                if (button.getAttribute('data-style-action') === 'edit' && style) {
                    if ($('#aiditor-style-name')) {
                        $('#aiditor-style-name').value = style.name || '';
                    }
                    if ($('#aiditor-style-description')) {
                        $('#aiditor-style-description').value = style.description || '';
                    }
                    if ($('#aiditor-style-prompt')) {
                        $('#aiditor-style-prompt').value = style.prompt || '';
                    }
                    setNotice(notice, '已载入文章风格，可修改后保存为同名风格。', 'success');
                    return;
                }

                if (button.getAttribute('data-style-action') === 'delete') {
                    setNotice(notice, '正在删除文章风格…', 'success');
                    api('article-styles/' + encodeURIComponent(styleId), {
                        method: 'DELETE'
                    }).then(function (data) {
                        renderArticleStyles(data.styles || []);
                        setNotice(notice, '文章风格已删除。', 'success');
                    }).catch(function (error) {
                        setNotice(notice, error.message, 'error');
                    });
                }
            });
        }
    }

    function initModelProfileManager(notice) {
        var saveButton = $('#aiditor-save-model-profile');
        var clearButton = $('#aiditor-clear-model-profile');
        var list = $('#aiditor-model-profile-list');
        var defaultSelect = $('#aiditor-default-model-profile');
        var form = $('#aiditor-settings-form');

        if (saveButton) {
            saveButton.addEventListener('click', function () {
                var profile;
                var found = false;
                var previousProfiles = state.modelProfiles.slice();
                var previousDefaultId = defaultSelect ? defaultSelect.value : '';

                try {
                    profile = readModelProfileEditor();
                } catch (error) {
                    setNotice(notice, error.message, 'error');
                    return;
                }

                state.modelProfiles = state.modelProfiles.map(function (item) {
                    if (item.profile_id === profile.profile_id) {
                        found = true;
                        if (!profile.api_key && item.api_key_configured) {
                            profile.api_key_configured = true;
                            profile.api_key_masked = item.api_key_masked || '';
                        }
                        return profile;
                    }

                    return item;
                });

                if (!found) {
                    state.modelProfiles.push(profile);
                }

                renderModelProfiles(defaultSelect ? defaultSelect.value : profile.profile_id);
                saveSettingsForm(form, notice, '正在保存模型配置…', '模型配置已保存。').then(function () {
                    clearModelProfileEditor();
                }).catch(function () {
                    state.modelProfiles = previousProfiles;
                    renderModelProfiles(previousDefaultId);
                });
            });
        }

        if (clearButton) {
            clearButton.addEventListener('click', function () {
                clearModelProfileEditor();
                setNotice(notice, '模型表单已清空。', 'success');
            });
        }

        if (defaultSelect) {
            defaultSelect.addEventListener('change', function () {
                var previousDefaultId = (getCurrentSettings() || {}).default_model_profile_id || '';
                renderModelProfiles(defaultSelect.value);
                saveSettingsForm(form, notice, '正在保存默认模型…', '默认模型已保存。').catch(function () {
                    defaultSelect.value = previousDefaultId;
                    renderModelProfiles(previousDefaultId);
                });
            });
        }

        if (list) {
            list.addEventListener('click', function (event) {
                var button = event.target.closest('[data-model-profile-action]');
                var profileId;
                var profile;
                var action;

                if (!button) {
                    return;
                }

                action = button.getAttribute('data-model-profile-action');
                profileId = button.getAttribute('data-model-profile-id') || '';
                profile = state.modelProfiles.find(function (item) {
                    return item.profile_id === profileId;
                });

                if ('edit' === action && profile) {
                    loadModelProfileEditor(profile);
                    setNotice(notice, '已载入模型配置，修改后点击“保存模型配置”。', 'success');
                    return;
                }

                if ('delete' === action) {
                    var previousProfiles = state.modelProfiles.slice();
                    var previousDefaultId = defaultSelect ? defaultSelect.value : '';

                    if (!window.confirm('确定删除这个模型配置？已创建的任务会继续记录原模型 ID，但后续不应再选择已删除模型。')) {
                        return;
                    }

                    state.modelProfiles = state.modelProfiles.filter(function (item) {
                        return item.profile_id !== profileId;
                    });

                    if (defaultSelect && defaultSelect.value === profileId) {
                        defaultSelect.value = '';
                    }

                    renderModelProfiles(defaultSelect ? defaultSelect.value : '');
                    saveSettingsForm(form, notice, '正在删除模型配置…', '模型配置已删除。').catch(function () {
                        state.modelProfiles = previousProfiles;
                        renderModelProfiles(previousDefaultId);
                    });
                }
            });
        }
    }

    function parseGenericFieldSchema() {
        var fieldInput = $('#aiditor-generic-fields');

        if (!fieldInput) {
            return [];
        }

        try {
            return JSON.parse(fieldInput.value || '[]');
        } catch (error) {
            throw new Error('目标字段 JSON 格式不正确：' + error.message);
        }
    }

    function sanitizeFieldKey(value) {
        return String(value || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
    }

    function isRewriteCandidateField(field) {
        var key = sanitizeFieldKey(field && field.key);
        var type = String(field && field.type ? field.type : 'text');

        if (!key || ['text', 'textarea', 'html'].indexOf(type) === -1) {
            return false;
        }

        return !/(^|_)(url|link|links|image|img|photo|cover|homepage|website)(_|$)/i.test(key);
    }

    function shouldDefaultRewriteField(field) {
        var key = sanitizeFieldKey(field && field.key);

        return ['summary', 'description', 'content', 'body'].indexOf(key) !== -1;
    }

    function renderGenericRewriteFields() {
        var container = $('#aiditor-generic-rewrite-fields');
        var priorInputs = $all('[data-generic-rewrite-field]');
        var hasPriorSelection = priorInputs.length > 0;
        var selected = priorInputs.filter(function (input) {
            return input.checked;
        }).map(function (input) {
            return input.value;
        });
        var fields;

        if (!container) {
            return;
        }

        try {
            fields = parseGenericFieldSchema();
        } catch (error) {
            container.innerHTML = '<div class="aiditor-rewrite-fields-hint is-error">' + escapeHtml(error.message) + '</div>';
            return;
        }

        if (!fields.length) {
            container.innerHTML = '<div class="aiditor-rewrite-fields-hint">请先在“高级字段设置”中配置目标字段。</div>';
            return;
        }

        container.innerHTML = '<div class="aiditor-rewrite-field-grid">' + fields.map(function (field) {
            var key = sanitizeFieldKey(field.key);
            var label = field.label || key || '未命名字段';
            var type = field.type || 'text';
            var eligible = isRewriteCandidateField(field);
            var checked = eligible && (hasPriorSelection ? selected.indexOf(key) !== -1 : shouldDefaultRewriteField(field));
            var hint = eligible
                ? (key === 'title' ? '可选' : '会重写')
                : '不重写';

            return '<label class="aiditor-rewrite-field' + (eligible ? '' : ' is-disabled') + '">' +
                '<input type="checkbox" data-generic-rewrite-field value="' + escapeHtml(key) + '"' + (eligible ? '' : ' disabled') + (checked ? ' checked' : '') + ' />' +
                '<span><strong>' + escapeHtml(label) + '</strong><small>' + escapeHtml(key || '-') + ' · ' + escapeHtml(type) + '</small><em>' + escapeHtml(hint) + '</em></span>' +
            '</label>';
        }).join('') + '</div>' +
        '<p class="description aiditor-rewrite-fields-note">默认建议重写“摘要”和“正文”；标题按需勾选。</p>';
    }

    function getSelectedRewriteFields() {
        return $all('[data-generic-rewrite-field]').filter(function (input) {
            return input.checked && !input.disabled;
        }).map(function (input) {
            return sanitizeFieldKey(input.value);
        }).filter(function (key, index, list) {
            return key && list.indexOf(key) === index;
        });
    }

    function getGenericPayload() {
        var urlInput = $('#aiditor-generic-url');
        var requirementInput = $('#aiditor-generic-requirement');

        return {
            url: urlInput ? urlInput.value : '',
            requirement: requirementInput ? requirementInput.value : '',
            instruction: requirementInput ? requirementInput.value : '',
            field_schema: parseGenericFieldSchema(),
            rewrite_fields: getSelectedRewriteFields()
        };
    }

    function renderGenericResult(data) {
        var result = $('#aiditor-generic-result');

        state.genericLastResult = data || null;
        state.genericProcess.raw = data || null;

        if (!result) {
            return;
        }

        renderGenericProcessTabs();
    }

    function setGenericActiveTab(tab) {
        state.genericProcess.activeTab = tab || 'page';
        renderGenericProcessTabs();
    }

    function renderGenericProcessTabs() {
        var container = $('#aiditor-generic-result');
        var tab = state.genericProcess.activeTab || 'page';

        $all('[data-generic-result-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-generic-result-tab') === tab);
        });

        if (!container) {
            return;
        }

        if (tab === 'page') {
            container.innerHTML = renderGenericPagePanel(state.genericProcess.page);
            return;
        }

        if (tab === 'urls') {
            container.innerHTML = renderGenericUrlsPanel(state.genericProcess.urls);
            return;
        }

        if (tab === 'fields') {
            container.innerHTML = renderGenericFieldsPanel(state.genericProcess.fields);
            return;
        }

        container.innerHTML = '<pre class="aiditor-json-preview">' + escapeHtml(JSON.stringify(state.genericProcess.raw || {}, null, 2)) + '</pre>';
    }

    function showGenericProgress(tab, message) {
        var container = $('#aiditor-generic-result');

        setGenericActiveTab(tab);

        if (container) {
            container.innerHTML = '<div class="aiditor-progress-state"><span class="spinner is-active"></span><strong>' + escapeHtml(message || '正在处理…') + '</strong></div>';
        }
    }

    function renderGenericPagePanel(data) {
        var page = data && data.page ? data.page : null;

        if (!page) {
            return '<div class="aiditor-empty-state">尚未抓取页面。点击左侧第 1 步“开始分析”后，这里会显示列表页标题、描述、正文预览和链接数量。</div>';
        }

        return '<div class="aiditor-result-summary-grid">' +
            '<div class="aiditor-result-summary"><small>页面标题</small><strong>' + escapeHtml(page.title || '-') + '</strong></div>' +
            '<div class="aiditor-result-summary"><small>页面 URL</small><strong>' + escapeHtml(page.url || '-') + '</strong></div>' +
            '<div class="aiditor-result-summary"><small>链接数量</small><strong>' + escapeHtml((page.links || []).length || 0) + '</strong></div>' +
            '</div>' +
            '<p class="description">' + escapeHtml(page.description || '未读取到页面 description。') + '</p>' +
            '<div class="aiditor-text-preview">' + escapeHtml(String(page.text || '').slice(0, 1600)) + '</div>';
    }

    function renderGenericUrlsPanel(data) {
        var items = data && data.result && data.result.items ? data.result.items : [];

        if (!items.length) {
            return '<div class="aiditor-empty-state">尚未发现详情页 URL。完成第 1 步后，这里会以表格展示 AI 识别出的候选详情页。</div>';
        }

        return '<table class="widefat striped aiditor-visual-table"><thead><tr>' +
            '<th>标题</th><th>详情页 URL</th><th>置信度</th><th>说明</th>' +
            '</tr></thead><tbody>' + items.map(function (item) {
                return '<tr>' +
                    '<td>' + escapeHtml(item.title || '-') + '</td>' +
                    '<td><a href="' + escapeHtml(item.url || '#') + '" target="_blank" rel="noreferrer">' + escapeHtml(item.url || '-') + '</a></td>' +
                    '<td>' + escapeHtml(item.confidence || 0) + '</td>' +
                    '<td>' + escapeHtml(item.reason || '-') + '</td>' +
                '</tr>';
            }).join('') + '</tbody></table>';
    }

    function renderGenericFieldsPanel(data) {
        var fields = data && data.fields ? data.fields : null;
        var keys;

        if (!fields) {
            return '<div class="aiditor-empty-state">尚未抽取详情页字段。发现详情页后，点击左侧第 2 步“抽取样例字段”会读取第一个候选详情页并展示字段结果。</div>';
        }

        keys = Object.keys(fields).filter(function (key) {
            return ['notes', 'missing_fields'].indexOf(key) === -1;
        });

        return '<table class="widefat striped aiditor-visual-table"><thead><tr>' +
            '<th>字段</th><th>内容</th>' +
            '</tr></thead><tbody>' + keys.map(function (key) {
                var value = fields[key];
                if (typeof value === 'object') {
                    value = JSON.stringify(value, null, 2);
                }

                return '<tr><td><strong>' + escapeHtml(key) + '</strong></td><td>' + escapeHtml(String(value || '').slice(0, 1200)) + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    function renderTemplates(templates) {
        var container = $('#aiditor-template-list');
        var select = $('#aiditor-template-select');

        state.genericTemplates = templates || [];

        if (select) {
            select.innerHTML = state.genericTemplates.length
                ? state.genericTemplates.map(function (template) {
                    return '<option value="' + escapeHtml(template.template_id || '') + '">' + escapeHtml(template.name || '未命名采集模板') + '</option>';
                }).join('')
                : '<option value="">请先创建采集模板</option>';
        }

        if (!container) {
            return;
        }

        if (!state.genericTemplates.length) {
            container.innerHTML = '<p class="description">暂无模板。</p>';
            return;
        }

        container.innerHTML = state.genericTemplates.map(function (template) {
            return '<article class="aiditor-template-card aiditor-template-card-compact">' +
                '<div class="aiditor-template-card-header">' +
                    '<div><strong>' + escapeHtml(template.name || '未命名采集模板') + '</strong>' +
                    '<p class="description">' + escapeHtml(template.source_url || '-') + '</p></div>' +
                    '<div class="aiditor-template-card-actions">' +
                        '<span class="aiditor-pill is-muted">' + escapeHtml(template.source_mode === 'detail' ? '详情页' : '列表页') + '</span>' +
                        '<button type="button" class="button button-small" data-template-action="delete" data-template-id="' + escapeHtml(template.template_id || '') + '">删除</button>' +
                    '</div>' +
                '</div>' +
                '<p>' + escapeHtml(template.description || template.extraction_prompt || '未填写说明。') + '</p>' +
                '<small>字段数：' + escapeHtml((template.field_schema || []).length || 0) + '，重写字段：' + escapeHtml((template.rewrite_fields || []).join('、') || '无') + '，更新时间：' + escapeHtml(formatDateTime(template.updated_at)) + '</small>' +
            '</article>';
        }).join('');
    }

    function refreshTemplates() {
        return api('templates').then(function (data) {
            renderTemplates(data.templates || []);
            return data;
        });
    }

    function initGenericTemplates() {
        var notice = $('#aiditor-generic-notice');
        var startButton = $('#aiditor-generic-start-button');
        var fetchButton = $('#aiditor-generic-fetch-button');
        var discoverButton = $('#aiditor-generic-discover-button');
        var extractButton = $('#aiditor-generic-extract-button');
        var saveButton = $('#aiditor-generic-save-button');
        var refreshButton = $('#aiditor-refresh-templates');

        if (!$('#aiditor-generic-form')) {
            return;
        }

        renderGenericRewriteFields();

        if ($('#aiditor-generic-fields')) {
            $('#aiditor-generic-fields').addEventListener('input', renderGenericRewriteFields);
            $('#aiditor-generic-fields').addEventListener('change', renderGenericRewriteFields);
        }

        $all('[data-generic-result-tab]').forEach(function (button) {
            button.addEventListener('click', function () {
                setGenericActiveTab(button.getAttribute('data-generic-result-tab'));
            });
        });

        renderGenericProcessTabs();

        if (startButton) {
            startButton.addEventListener('click', function () {
                var payload;

                try {
                    payload = getGenericPayload();
                } catch (error) {
                    setNotice(notice, error.message, 'error');
                    return;
                }

                setNotice(notice, '正在抓取列表页…', 'success');
                showGenericProgress('page', '正在抓取列表页内容…');
                api('generic/fetch-page', {
                    method: 'POST',
                    body: JSON.stringify({url: payload.url})
                }).then(function (data) {
                    state.genericProcess.page = data;
                    state.genericProcess.raw = data;
                    setGenericActiveTab('page');
                    setNotice(notice, '列表页抓取完成，正在识别详情页 URL…', 'success');
                    showGenericProgress('urls', 'AI 正在识别详情页 URL…');

                    return api('generic/discover-urls', {
                        method: 'POST',
                        body: JSON.stringify(payload)
                    });
                }).then(function (data) {
                    state.genericProcess.urls = data;
                    state.genericProcess.raw = data;
                    setGenericActiveTab('urls');
                    setNotice(notice, '详情页 URL 识别完成。可继续抽取首个详情页字段，或直接保存模板。', 'success');
                }).catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        if (fetchButton) {
            fetchButton.addEventListener('click', function () {
                setNotice(notice, '正在抓取页面…', 'success');
                showGenericProgress('page', '正在抓取页面内容…');
                api('generic/fetch-page', {
                    method: 'POST',
                    body: JSON.stringify({url: getGenericPayload().url})
                }).then(function (data) {
                    state.genericProcess.page = data;
                    renderGenericResult(data);
                    setGenericActiveTab('page');
                    setNotice(notice, '页面抓取完成。', 'success');
                }).catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        if (discoverButton) {
            discoverButton.addEventListener('click', function () {
                setNotice(notice, 'AI 正在识别详情页 URL…', 'success');
                showGenericProgress('urls', 'AI 正在识别详情页 URL…');
                api('generic/discover-urls', {
                    method: 'POST',
                    body: JSON.stringify(getGenericPayload())
                }).then(function (data) {
                    state.genericProcess.urls = data;
                    renderGenericResult(data);
                    setGenericActiveTab('urls');
                    setNotice(notice, '详情页 URL 识别完成。', 'success');
                }).catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        if (extractButton) {
            extractButton.addEventListener('click', function () {
                var payload;
                var firstUrl = state.genericProcess.urls && state.genericProcess.urls.result && state.genericProcess.urls.result.items && state.genericProcess.urls.result.items[0]
                    ? state.genericProcess.urls.result.items[0].url
                    : '';

                try {
                    payload = getGenericPayload();
                } catch (error) {
                    setNotice(notice, error.message, 'error');
                    return;
                }

                if (firstUrl) {
                    payload.url = firstUrl;
                }

                setNotice(notice, 'AI 正在抽取字段…', 'success');
                showGenericProgress('fields', 'AI 正在抽取详情页字段…');
                api('generic/extract-fields', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                }).then(function (data) {
                    state.genericProcess.fields = data;
                    renderGenericResult(data);
                    setGenericActiveTab('fields');
                    setNotice(notice, '字段抽取完成。', 'success');
                }).catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        if (saveButton) {
            saveButton.addEventListener('click', function () {
                var nameInput = $('#aiditor-generic-template-name');
                var modeInput = $('#aiditor-generic-mode');
                var payload;

                try {
                    payload = getGenericPayload();
                } catch (error) {
                    setNotice(notice, error.message, 'error');
                    return;
                }

                payload.name = nameInput && nameInput.value ? nameInput.value : '通用采集模板';
                payload.source_url = payload.url;
                payload.source_mode = modeInput ? modeInput.value : 'list';
                payload.extraction_prompt = payload.requirement;
                payload.rewrite_fields = getSelectedRewriteFields();
                payload.sample = {
                    page: state.genericProcess.page || null,
                    urls: state.genericProcess.urls || null,
                    fields: state.genericProcess.fields || null
                };
                payload.status = 'draft';

                setNotice(notice, '正在保存模板…', 'success');
                api('templates', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                }).then(function (data) {
                    renderGenericResult(data);
                    setNotice(notice, '模板已保存。', 'success');
                    return refreshTemplates();
                }).catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        var templateList = $('#aiditor-template-list');
        if (templateList) {
            templateList.addEventListener('click', function (event) {
                var button = event.target.closest('[data-template-action]');
                var templateId;
                var template;

                if (!button || button.getAttribute('data-template-action') !== 'delete') {
                    return;
                }

                templateId = button.getAttribute('data-template-id') || '';
                template = state.genericTemplates.find(function (item) {
                    return item.template_id === templateId;
                });

                if (!templateId) {
                    setNotice(notice, '缺少采集模板 ID，无法删除。', 'error');
                    return;
                }

                if (!window.confirm('确定删除采集模板“' + (template && template.name ? template.name : templateId) + '”？删除后不能再用它创建新的队列任务，已经创建的任务不受影响。')) {
                    return;
                }

                setNotice(notice, '正在删除采集模板…', 'success');
                api('templates/' + encodeURIComponent(templateId), {
                    method: 'DELETE'
                }).then(function () {
                    setNotice(notice, '采集模板已删除。', 'success');
                    return refreshTemplates();
                }).catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        if (refreshButton) {
            refreshButton.addEventListener('click', function () {
                refreshTemplates().catch(function (error) {
                    setNotice(notice, error.message, 'error');
                });
            });
        }

        refreshTemplates().catch(function () {});
    }

    document.addEventListener('DOMContentLoaded', function () {
        var config = getAppConfig();

        initTabs();
        populateSettings(config.settings || {});
        renderRuns(config.runs || []);
        renderRunDetail(null);
        initImport();
        initGenericTemplates();
        initSettings();

        refreshRunViews().then(function () {
            if (state.activeRunId && isProcessableStatus(state.activeRunStatus)) {
                ensurePolling();
            }
        });
    });
}());
