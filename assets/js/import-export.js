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

    /*
     * インポート実行前の確認
     */
    const importPanel =
        root.querySelector(
            '[data-nx-import-export-panel="import"]'
        );

    const importForm =
        importPanel
            ? importPanel.querySelector(
                "form"
            )
            : null;

    if (importForm) {
        importForm.addEventListener(
            "submit",
            (event) => {
                const confirmed =
                    window.confirm(
                        "インポートを実行します。\n"
                        + "既存データが更新される場合があります。"
                    );

                if (!confirmed) {
                    event.preventDefault();
                }
            }
        );
    }

    /*
     * 二重送信を防止
     */
    root.querySelectorAll("form").forEach(
        (form) => {
            form.addEventListener(
                "submit",
                () => {
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