---
status: current
updated: 2026-10-21
owner: lead
---

# CLA 补签执行包（R1 路径）

> 配套文件：[`CLA.md`](../../../CLA.md)、[签署记录与历史贡献覆盖清单](cla-signing-register.md)、[法务可行性核对](cla-feasibility-2026-10-21.md)。
>
> **为什么必须补签**：AGPL 授权不可撤销，文件级排除不能切断授权链（AGPL 第 0 条把「修改」定义为复制或改编全部/部分作品，第 5(c) 条要求按 AGPL 授权整体作品）。补签是让「修改后闭源」合法成立的唯一机制，也是成本最低的一条路。
>
> **本文件不含个人邮箱**：联系邮箱请从 git 历史作者信息或 GitHub 公开资料取用，不要写回仓库。

## 1. 执行范围

需补签的历史贡献者身份组（共 9 组、298 次提交）：

| 身份组                       | GitHub 用户名                                       | 提交数 | 联系状态 | 签署范围   | 签署时间 |
| ---------------------------- | --------------------------------------------------- | -----: | -------- | ---------- | -------- |
| yealqp / 仙林loy             | `yealqp`、`仙林loy`                                 |    115 | 待联系   | 含历史贡献 | —        |
| YeHuaiJing 叶淮璟            | `YeHuaiJing`、`叶淮璟`                              |     50 | 待联系   | 含历史贡献 | —        |
| Shanshui 山重水复            | `Shanshui-山重水复`、`Shanshui2024`、`Shanshui2023` |     46 | 待联系   | 含历史贡献 | —        |
| PigFully / PingFully         | `PigFully`、`PingFully`                             |     41 | 待联系   | 含历史贡献 | —        |
| xiobb                        | `xiobb`                                             |     18 | 待联系   | 含历史贡献 | —        |
| xioiox                       | `xioiox`                                            |     14 | 待联系   | 含历史贡献 | —        |
| lan134342                    | `lan134342`                                         |      8 | 待联系   | 含历史贡献 | —        |
| 咕咕摆烂真君 / Gugubailan520 | `咕咕摆烂真君`、`Gugubailan520`                     |      4 | 待联系   | 含历史贡献 | —        |
| FurLLCN                      | `FurLLCN`                                           |      2 | 待联系   | 含历史贡献 | —        |

> `Cascade`（`cascade@example.local`，1 次提交、1 个文件）来源不明，暂不联系，在权属确认前按「不包含」处理。

## 2. 签署声明原文（两种，择一）

### 2.1 仅覆盖生效日之后的新贡献

```text
I have read the CLA Document and I hereby sign the CLA（我已阅读并同意 CLA.md）
```

### 2.2 同时覆盖 2026-10-21 之前的历史贡献（**本轮请用这一条**）

```text
I have read the CLA Document and I hereby sign the CLA（我已阅读并同意 CLA.md），
and I confirm this CLA also covers my prior contributions before 2026-10-21
（并适用于我在 2026-10-21 前已提交的全部贡献）。
```

> 只用 2.1 的话，历史贡献仍按 AGPL 处理，不能进商业版本。

## 3. 联系函模板（中文）

```text
主题：TuraIDC CLA 签署邀请（历史贡献追溯覆盖）

你好，

感谢你此前为 TuraIDC（https://github.com/25Cloud/TuraIDC）作出的贡献。

说明一下背景：TuraIDC 采用 AGPL-3.0-or-later 与商业许可的双许可模式——任何人可以免费按 AGPL 使用；不愿承担 AGPL 强制开源义务的组织可以取得商业许可闭源使用。
要让商业许可在法律上成立，项目方需要拥有把贡献同时按 AGPL 与商业许可分发的再许可授权，因此我们从 2026-10-21 起启用了贡献者许可协议（CLA）。

**这不是追溯适用**：2026-10-21 之前你已提交的贡献，继续按原来的 AGPL 授权有效，项目方不会、也无法单方面改动它。你的署名与贡献记录也完整保留在仓库历史里。
我们只是希望**由你自愿补签**，把 2026-10-21 之前的贡献一并纳入双许可授权，这样你参与过的代码才能进入闭源商业版本。

如果你愿意，请在任意一个 Pull Request 的评论里回复下面这段声明（可署名，也可匿名/化名）：

    I have read the CLA Document and I hereby sign the CLA（我已阅读并同意 CLA.md），
    and I confirm this CLA also covers my prior contributions before 2026-10-21
    （并适用于我在 2026-10-21 前已提交的全部贡献）。

也可以直接回复本邮件表示同意，我们会把你的声明登记进项目方的签署记录（只登记 GitHub 用户名、签署范围、时间与声明原文，不对外公开邮箱）。

你的选择：
1. 同意补签 —— 回复上面的声明即可；
2. 暂不补签 —— 完全没有关系，你的贡献继续按 AGPL 有效，不影响署名，也不会被从仓库移除；
3. 有疑问 —— 直接回信，我们把 CLA 全文（https://github.com/25Cloud/TuraIDC/blob/main/CLA.md）发给你。

顺带一提，CLA 全文里也写明：你不授予的，项目方不会拿去商用；未取得再许可授权的历史贡献不会进入商业版本。

谢谢
TuraIDC 项目方
contact@turaidc.com
```

## 4. 联系函模板（英文）

```text
Subject: TuraIDC CLA signing request (retroactive coverage of past contributions)

Hi,

Thanks for your earlier contributions to TuraIDC (https://github.com/25Cloud/TuraIDC).

A short background: TuraIDC is dual-licensed — AGPL-3.0-or-later for everyone, plus a
commercial license for organisations that cannot take on AGPL's source-disclosure obligations.
For that commercial license to be legally clean, the project needs the right to relicense
contributions under both AGPL and the commercial terms, so we introduced a CLA effective 2026-10-21.

**This is not retroactive.** Contributions you made before 2026-10-21 remain under AGPL exactly as
before; we cannot and will not change that unilaterally. Your attribution stays in the repository history.
We would simply like you to **opt in**, so that the code you contributed can also be included in the
closed-source commercial edition.

If you are willing, please reply with the following statement in any pull request comment
(you may sign your name, or use a pseudonym):

    I have read the CLA Document and I hereby sign the CLA,
    and I confirm this CLA also covers my prior contributions before 2026-10-21.

Alternatively, just reply to this email — we will record the statement (GitHub username, scope, date
and the original text only; your email is never published).

Your options: (1) sign, (2) don't sign — nothing changes for you, your contribution stays AGPL and
your attribution is preserved, (3) ask questions — we will send you the full CLA text.

Thanks
The TuraIDC project
contact@turaidc.com
```

## 5. 发送方式与话术边界

- **优先 GitHub**：在贡献者仍有活动的仓库下开 issue 或在其 PR 下评论，天然带 GitHub 账号与时间戳，取证成本最低。
- **邮件兜底**：主题「CLA 签署 - GitHub 用户名」，正文写明 2.2 的声明原文与签署范围。
- **不要说**「不签就把你的代码从商业版删掉」这类施压表述；正确表述是「未取得再许可授权的贡献不会进入商业版本」，这是许可证的客观规则，不是对个人的要求。
- **不要说**「你已经默认同意了 CLA」——生效日之前的贡献不自动适用 CLA。
- 可以提供署名匿名/化名的选项（`CLA.md` 第 9 条第 3 项）。

## 6. 签署记录登记

收到声明后按 [清单 §6](cla-signing-register.md) 的字段登记，签署记录存项目方私有记录（邮件归档），仓库内只公示已签署者名单与聚合统计。

| 身份组 | GitHub 用户名 | 签署范围 | 签署时间 | 声明原文出处 | 登记人 |
| ------ | ------------- | -------- | -------- | ------------ | ------ |
| —      | —             | —        | —        | —            | —      |

## 7. 完成后要做的事

1. 全部外部贡献者补签完成后，在[清单 §5](cla-signing-register.md)里把「仅外部写过」与「混合」两类文件整体放行，解除 R1 门禁；
2. 商业版构建产物做一次逐文件比对并归档记录（清单 §7 第 3 条）；
3. 同步更新 `CLA.md` 第四部分的待办状态与 `LICENSE-COMMERCIAL.md` 第 9 条第 6 项。
