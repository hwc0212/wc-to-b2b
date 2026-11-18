# WooCommerce 隔离回归

在可丢弃的 WordPress + WooCommerce 站点运行这些脚本。设置 `WP_ENVIRONMENT_TYPE` 为 `local`，使用测试数据库，并为 CLI 设置 `B2B_TEST_DISPOSABLE=1` 和 `B2B_TEST_WP_ROOT`。HTTP 场景通过容器内的回环服务器执行，地址见 `http.php`。启用 `catalog-site-fixture.php` 作为测试站的 MU 插件，可模拟站点自有入口并拦截发信。目录测试使用真实 Contact Form 7 生成的合成表单。

- `setup.php` 提供激活、表单、固定链接和切换模式的测试准备。设置固定链接后，在新请求中执行 `setup.php flush`。
- `modes.php` 分别接受 `fresh`、`legacy` 和 `ambiguous`。原版升级应先激活原版 2.1.2，保留数据库并替换插件文件，再运行 `legacy`。
- `catalog.php` 使用带非零价的商品和变体，以四个身份通过 HTTP 校验目录、原生变体查询、快速查看、Store API、后台价格和购买入口。
- `automation-guards.php` 使用真实 WooCommerce 订单和两个测试收件通道，主动触发新旧回调并核对持久状态。
- `full-workflow.php` 通过员工报价、签名链接、客户账户和模式切换验证授权历史金额。脚本最后停用插件并执行普通卸载回调。

各路径使用独立的测试数据库。测试样本使用合成账号、订单、收件地址和 WhatsApp 替身。
