# WooCommerce to B2B

[![GitHub](https://img.shields.io/badge/GitHub-wc--to--b2b-blue?logo=github)](https://github.com/hwc0212/wc-to-b2b)
[![Version](https://img.shields.io/badge/Version-2.1.2-green)](https://github.com/hwc0212/wc-to-b2b/releases)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple)](https://woocommerce.com/)

WooCommerce to B2B 把 WooCommerce 的直接在线付款流程改造成“询价/报价请求 → 人工审核价格和运费 → 正式报价 → 线下付款 → 发货”的 B2B 工作流。

- GitHub 仓库：[https://github.com/hwc0212/wc-to-b2b](https://github.com/hwc0212/wc-to-b2b)
- Releases：[https://github.com/hwc0212/wc-to-b2b/releases](https://github.com/hwc0212/wc-to-b2b/releases)

## 当前工作方式

### 客户访问模式

在“WooCommerce → B2B Member Levels → Customer Access Mode”选择以下一种模式：

1. **允许访客询价**（默认）
   - 访客可以把商品加入询价单并提交。
   - 每一份访客询价都必须验证本次填写的邮箱。
   - 客户点击验证邮件里的链接之前，询价不会投递到设置的接待邮箱。
   - 邮箱验证通过后才通知接待人员；系统仍不会自动发送正式报价。

2. **必须注册**
   - 客户必须注册账户、点击注册验证邮件并登录，之后才能进入结账和提交报价请求。
   - 未登录访客不能提交询价或订单。
   - 已验证的登录客户不需要在每次提交时重复验证邮箱。

这两种模式只控制客户是否必须注册，不改变人工报价规则。**所有提交都必须由管理员检查并调整商品价格、运费，然后手动发送正式报价。插件没有自动生成或自动发送正式报价的开关。**

### 访客价格显示

“WooCommerce → B2B Member Levels → Guest Price Display”可独立设置访客是否看到 WooCommerce 零售价：

- 关闭（默认）：商品目录、商品页、购物车、结账、验证邮件和正式报价前的询价页面隐藏金额。
- 开启：访客看到零售价参考，但仍然不能直接购买；允许访客询价时仍必须先验证邮箱。

设置为“必须注册”时，这个开关仍可决定未登录访客浏览商品时是否看到零售价，但访客不能提交。

## 会员等级与价格

插件使用三个固定会员等级：

| 等级 | ID | 登录后看到的价格 |
| --- | --- | --- |
| 注册客户 | `registered` | WooCommerce 零售价 |
| 普通客户 | `regular` | 普通客户批发价 |
| VIP 客户 | `vip` | VIP 价 |

- 新账户验证邮箱后默认是“注册客户”。
- 管理员可在 WordPress 用户资料中手动改为“普通客户”或“VIP 客户”。
- 普通客户和 VIP 客户都可在“WooCommerce → B2B Member Levels”设置统一折扣规则。
- 每个商品和每个变体还可以单独填写普通客户固定价和 VIP 固定价。
- 商品/变体固定会员价优先；留空时才使用该等级的统一折扣。

## 人工报价流程

1. 访客提交询价并验证本次邮箱，或者已验证的会员登录后提交报价请求。
2. 系统把请求保存为“已验证/等待审核”，并通知后台及接待邮箱。
3. 管理员在 WooCommerce 订单中核对商品、数量和客户留言，调整商品单价与运费。
4. 管理员点击“Send quote to customer”后，系统才生成报价编号、有效期，并把正式报价发送给客户。
5. 客户查看并接受报价，根据报价上的银行账户、SWIFT、付款附言等信息在线下付款。
6. 管理员在订单中登记一笔或多笔付款记录；客户可登录账户或使用邮件中的安全链接查看。
7. 管理员登记一次或多次发货记录，包括承运商、单号、查询链接、发货内容和备注。
8. 报价、确认、付款、发货和订单状态更新会通过邮件通知客户。

结账只提供 B2B 询价/线下报价网关，不显示信用卡或其他在线支付网关，也不会在客户提交时自动创建正式报价。

## 线下付款与发货记录

- 在“WooCommerce → B2B Quote Settings”填写报价有效期和线下付款信息。
- 管理员可逐笔登记付款日期、金额、方式和银行流水号。
- 累计付款达到订单总额后，订单可进入已收款/处理中状态。
- 支持部分发货、多次发货、物流单号及跟踪链接。
- 登录客户可在“我的账户 → B2B Quotes & Orders”查看报价、付款余额和发货历史。
- 访客询价客户通过带签名的邮件链接安全查看询价及后续正式报价。

## 邮箱验证与通知

- 注册账户必须点击注册邮件中的验证链接后才能登录。
- 访客询价必须点击本次询价邮件中的验证链接后，询价才会发送到接待邮箱。
- 即使配置了 WhatsApp，访客询价的邮箱验证也不能被 WhatsApp 验证替代。
- 在“WooCommerce → B2B Settings → Reception Email”设置接收已验证询价的邮箱。
- 如果接待邮件发送失败，订单会记录失败状态，管理员可以在订单操作中重新发送。

## 语言与本地化

- 插件源码及缺省界面语言为英文，不需要单独的英文语言包。
- WordPress 会根据“设置 → 常规 → 站点语言”自动加载匹配的插件语言；没有匹配语言时安全回退到英文。
- 主 README 始终使用中文，方便中文管理员安装和配置。
- 每个内置语言都同时提供可编辑的 PO 和 WordPress 实际加载的 MO 文件。
- `languages/wc-to-b2b.pot` 是包含全部 453 条界面、邮件和订单状态文本的翻译模板，并保留订单状态、占位符等上下文。

安装包默认包含以下语言：

| 语言 | WordPress 语言代码 | 文件前缀 |
| --- | --- | --- |
| 英文（默认） | `en_US` / `en_GB` | 使用源码英文，无需语言包 |
| 简体中文 | `zh_CN` | `wc-to-b2b-zh_CN` |
| 繁体中文 | `zh_TW` | `wc-to-b2b-zh_TW` |
| 西班牙语 | `es_ES` | `wc-to-b2b-es_ES` |
| 法语 | `fr_FR` | `wc-to-b2b-fr_FR` |
| 德语 | `de_DE` | `wc-to-b2b-de_DE` |
| 意大利语 | `it_IT` | `wc-to-b2b-it_IT` |
| 葡萄牙语（巴西） | `pt_BR` | `wc-to-b2b-pt_BR` |
| 日语 | `ja` | `wc-to-b2b-ja` |
| 韩语 | `ko_KR` | `wc-to-b2b-ko_KR` |
| 俄语 | `ru_RU` | `wc-to-b2b-ru_RU` |

## 安装与配置

### 系统要求

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+

### 安装

1. 下载 Release 中的 `wc-to-b2b-2.1.2.zip`。
2. 在 WordPress 后台进入“插件 → 安装插件 → 上传插件”。
3. 上传 ZIP、安装并激活；确保 WooCommerce 已安装并激活。

### 建议配置顺序

1. 在“WooCommerce → B2B Settings”设置接待邮箱和验证链接有效期。
2. 在“WooCommerce → B2B Member Levels”选择客户访问模式、访客价格显示方式和普通/VIP 折扣。
3. 在各商品或变体的价格区域填写需要覆盖统一规则的普通/VIP 固定价。
4. 在“WooCommerce → B2B Quote Settings”设置报价有效期和线下付款资料。
5. 用访客和三个会员等级分别测试商品页、购物车、提交、邮件及报价查看页面。

如果旧版本曾启用“Replace add to cart buttons with WhatsApp buttons”，请在 B2B 设置中关闭该选项，商品页才会恢复加入询价单并进入报价请求流程。

## 兼容与升级

- 支持 WooCommerce HPOS 订单存储。
- 从旧版升级时，原 `standard`、`silver`、`gold` 等级分别兼容迁移为 `registered`、`regular`、`vip`。
- 旧商品的 Silver/Gold 固定价继续作为普通客户价/VIP 价兼容读取。
- 2.1.1 升级会永久关闭旧版自动报价选项，现有正式报价和付款/发货记录不受影响。

## 更新日志

### 版本 2.1.2

- 明确英文为插件默认和最终回退语言，README 保持中文。
- 默认包含简体中文、繁体中文、西班牙语、法语、德语、意大利语、葡萄牙语（巴西）、日语、韩语和俄语。
- 每种内置语言均包含完整的 453 条 PO/MO 翻译，并支持订单状态上下文。
- 新增完整 POT 翻译模板，保留 PHP 格式化占位符和源文件位置。
- 修复旧简体中文 PO 的语法错误并生成可实际加载的 MO 文件。

### 版本 2.1.1

- 新增“允许访客询价 / 必须注册”客户访问模式开关。
- 无论访客价格显示或隐藏，允许访客询价时都必须验证邮箱后才投递。
- 必须注册模式会阻止访客结账，要求注册、验证邮箱并登录。
- 移除所有自动正式报价路径；统一为管理员调整价格和运费后人工发送。
- 正式报价前的账户页和状态邮件明确标记价格等待管理员审核。
- 更新 README 和简体中文翻译。

### 版本 2.1.0

- 固定注册客户、普通客户、VIP 客户三级价格权限。
- 新注册账户必须验证邮箱后才能登录，注册客户默认查看零售价。
- 新增访客价格显示开关，可隐藏金额或显示零售价参考。
- 普通客户和 VIP 支持商品、变体独立固定价，统一折扣作为兜底规则。
- 访客询价验证邮箱前不投递，隐藏访客价格时正式报价前也不显示金额。

### 版本 2.0.0

- 新增会员等级、用户等级分配、商品及变体等级价。
- 新增正式报价、报价有效期、线下付款资料和可打印客户报价页。
- 新增多笔付款、部分发货及多次物流记录。
- 新增客户中心 B2B 报价订单入口、状态与履约邮件通知。
- 新增 WooCommerce HPOS 订单存储兼容与签名链接访问控制。

## 支持

- [GitHub Issues](https://github.com/hwc0212/wc-to-b2b/issues)
- [GitHub Discussions](https://github.com/hwc0212/wc-to-b2b/discussions)
- [huwencai.com](https://huwencai.com)

## 许可证

GPL v2 或更高版本。
