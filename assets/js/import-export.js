(() => {
    "use strict";

    /*
     * 管理画面を取得
     */
    const root = document.querySelector(
        "[data-nx-import-export]"
    );

    if (!root) {
        return;
    }

    const tabs = Array.from(
        root.querySelectorAll(
            "[data-nx-import-export-tab]"
        )
    );

    const panels = Array.from(
        root.querySelectorAll(
            "[data-nx-import-export-panel]"
        )
    );

    /*
     * 指定したタブを表示
     */
    const activateTab = (tabName) => {
        tabs.forEach((tab) => {
            const isActive =
                tab.dataset.nxImportExportTab
                === tabName;

            tab.classList.toggle(
                "is-active",
                isActive
            );

            tab.setAttribute(
                "aria-selected",
                isActive ? "true" : "false"
            );

            tab.setAttribute(
                "tabindex",
                isActive ? "0" : "-1"
            );
        });

        panels.forEach((panel) => {
            const isActive =
                panel.dataset.nxImportExportPanel
                === tabName;

            panel.classList.toggle(
                "is-active",
                isActive
            );

            panel.hidden = !isActive;
        });
    };

    /*
     * タブへアクセシビリティ属性を追加
     */
    tabs.forEach((tab, index) => {
        const tabName =
            tab.dataset.nxImportExportTab;

        const panel = panels.find(
            (item) =>
                item.dataset.nxImportExportPanel
                === tabName
        );

        const tabId =
            `nx-import-export-tab-${index}`;

        const panelId =
            `nx-import-export-panel-${index}`;

        tab.id = tabId;
        tab.setAttribute(
            "role",
            "tab"
        );

        if (panel) {
            panel.id = panelId;
            panel.setAttribute(
                "role",
                "tabpanel"
            );

            panel.setAttribute(
                "aria-labelledby",
                tabId
            );

            tab.setAttribute(
                "aria-controls",
                panelId
            );
        }

        tab.addEventListener(
            "click",
            () => {
                activateTab(tabName);
            }
        );

        /*
         * 左右キーでタブを切り替える
         */
        tab.addEventListener(
            "keydown",
            (event) => {
                if (
                    event.key !== "ArrowLeft" &&
                    event.key !== "ArrowRight"
                ) {
                    return;
                }

                event.preventDefault();

                const direction =
                    event.key === "ArrowRight"
                        ? 1
                        : -1;

                const currentIndex =
                    tabs.indexOf(tab);

                const nextIndex =
                    (
                        currentIndex
                        + direction
                        + tabs.length
                    )
                    % tabs.length;

                const nextTab =
                    tabs[nextIndex];

                activateTab(
                    nextTab.dataset
                        .nxImportExportTab
                );

                nextTab.focus();
            }
        );
    });

    const tabList = root.querySelector(
        ".nx-import-export-tabs"
    );

    if (tabList) {
        tabList.setAttribute(
            "role",
            "tablist"
        );
    }

    /*
     * 初期表示タブ
     */
    const activeTab =
        tabs.find(
            (tab) =>
                tab.classList.contains(
                    "is-active"
                )
        )
        ?? tabs[0];

    if (activeTab) {
        activateTab(
            activeTab.dataset
                .nxImportExportTab
        );
    }

    // データを変更するフォームだけ、実行直前の確認を行う。
    root.querySelectorAll("form[data-confirm]").forEach((form) => {
        form.addEventListener("submit", (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    const importUpload = root.querySelector('form[action$="/preview"]');
    if (importUpload) {
        const contentOptions = importUpload.querySelector("[data-nx-content-options]");
        const conflictLegend = importUpload.querySelector("[data-nx-conflict-legend]");
        const conflictDefault = importUpload.querySelector("[data-nx-conflict-default]");
        const contentReferenceHelp = importUpload.querySelector("[data-nx-content-reference-help]");
        const urlOptions = importUpload.querySelector("[data-nx-url-options]");
        const debugOption = importUpload.querySelector("[data-nx-debug-option]");

        const updateOptions = () => {
            const selectedMode = importUpload.querySelector('input[name="mode"]:checked');
            const mode = selectedMode?.value;
            const categoriesOnly = selectedMode?.hasAttribute("data-nx-categories-only") ?? false;
            if (contentOptions) {
                contentOptions.hidden = mode === "settings";
                contentOptions.disabled = mode === "settings";
                conflictLegend.textContent = categoriesOnly
                    ? "同じスラッグがある場合"
                    : "同じスラッグ・メディア保存先がある場合";
                conflictDefault.textContent = mode === "csv" || categoriesOnly
                    ? "初期設定では、重複した項目をスキップします。"
                    : "初期設定では、既存データを上書きします。";
                contentReferenceHelp.hidden = categoriesOnly;
            }
            if (urlOptions) {
                urlOptions.hidden = categoriesOnly;
                urlOptions.disabled = categoriesOnly;
            }
            if (debugOption) {
                debugOption.hidden = mode !== "settings";
                debugOption.querySelector("input").disabled = mode !== "settings";
            }
        };
        importUpload.querySelectorAll('input[name="mode"]').forEach((input) => {
            input.addEventListener("change", updateOptions);
        });
        updateOptions();
    }

    const exportForm = root.querySelector('form[action$="/export"]');
    if (exportForm) {
        const csvScope = exportForm.querySelector("[data-nx-csv-scope]");
        if (csvScope) {
            const updateCsvScope = () => {
                const csv = exportForm.querySelector('input[name="mode"]:checked')?.value === "csv";
                csvScope.hidden = !csv;
                csvScope.querySelector("select").disabled = !csv;
            };
            exportForm.addEventListener("change", updateCsvScope);
            updateCsvScope();
        }
    }

    /*
     * データを更新するフォームの二重送信を防止
     */
    root.querySelectorAll("form").forEach(
        (form) => {
            if (form === exportForm) { return; }
            form.addEventListener(
                "submit",
                (event) => {
                    if (event.defaultPrevented) { return; }
                    const submitButton =
                        form.querySelector(
                            'button[type="submit"]'
                        );

                    if (!submitButton) {
                        return;
                    }

                    window.setTimeout(
                        () => {
                            submitButton.disabled =
                                true;

                            submitButton.textContent =
                                "処理中...";
                        },
                        0
                    );
                }
            );
        }
    );
})();
