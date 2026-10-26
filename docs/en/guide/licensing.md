---
source: guide/licensing.md
sourceHash: 8b604a62aed5d13a249d122964599deb6e34a92e8204ce318322fd63326437be
translatedAt: 2026-10-26
---

# Open Source and Commercial Licensing

TuraIDC adopts a **dual-licensing model of AGPL-3.0-or-later plus a commercial license**: free and open source by default, while organizations that do not want to take on the open-source obligations can purchase a commercial license and use it closed-source.

This document is written for **site operators and enterprise technical leads who deploy and operate TuraIDC**, and clarifies two things: which obligations you must fulfill on the free path, when you need to buy a commercial license, and how to obtain the source code and authorization.

> This document is a compliance summary compiled by the engineering side and **does not constitute legal advice**; the formal terms are governed by the [AGPL-3.0-or-later full text](https://github.com/25Cloud/TuraIDC/blob/main/LICENSE), the [commercial license draft](https://github.com/25Cloud/TuraIDC/blob/main/LICENSE-COMMERCIAL.md), and the signed authorization document between both parties. Consult legal counsel for disputed scenarios.

## 1. The Two Paths at a Glance

|                            | **AGPL-3.0-or-later (default, free)**                                                                                 | **Commercial license (200 CNY/year)**                                                                            |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Cost                       | Free                                                                                                                  | 200 CNY/year, charged per "each organization, each production deployment" (negotiable)                           |
| Closed source?             | **No**: after modification, providing external services requires publishing the corresponding source code             | **Yes**: closed-source use within the authorized scope, no publication after modification                        |
| Network service obligation | Triggers AGPL Section 13: provide users with the complete corresponding source code                                   | **Exempt** from Section 13                                                                                       |
| Redistribution             | Must be distributed as a whole under AGPL, retaining the license and notices                                          | Resale, white-labeling, and source code redistribution are prohibited                                            |
| Who it suits               | Individuals/organizations/small businesses that accept full open source and are willing to give back to the community | Sensitive enterprises: cannot publish source code, require private delivery, closed-source secondary development |

**A commercial license is not "pay to unlock features"**: payment only settles one thing — "legal closed-source use" — and unlocks no extra features and involves no feature crippling. (The commercial edition must satisfy the authorization chain requirements of the "Commercial version build rules" in `CLA.md`; until all historical contributors complete the supplementary signing, the code baselines of the commercial edition and the AGPL edition may differ, and any difference stems only from the authorization boundary — the terms listed in the order prevail in specific cases.)

## 2. The AGPL Free Path: Rights You Have

Subject to compliance with AGPL, you may:

- Download, deploy, and use it for commercial operations for free (including selling products/services to external customers);
- Modify the source code, perform secondary development, and develop your own plugins and themes;
- Copy and distribute this project (including modified versions), provided you comply with AGPL.

**AGPL protects users' freedom to obtain the source code and does not restrict you from making money with it.** Selling products and charging service fees are both fine; the problem only arises with "modified but not open-sourced" or "closed-source distribution".

## 3. The AGPL Free Path: Mandatory Obligations (Required)

### 3.1 Modified Code Must Be Published (Section 13)

AGPL Section 13 requires: if you modify this project and let users use this modified version over the network, you must provide those users with the **complete corresponding source code** (including your own changes).

- You may not deploy without open-sourcing;
- You may not circumvent this via "internal use", "private delivery", or "SaaS means we are not selling software";
- "Corresponding source code" includes the files you modified, plus the scripts and configuration needed to generate, install, and run that version (front-end build artifacts must map back to the source).

### 3.2 The Project Must Not Be Turned Into a Closed-Source Product and Redistributed

- External services after secondary development remain bound by AGPL;
- External distribution (sending to customers, customer deployment, images/installers) must be licensed as a whole under AGPL, and recipients likewise receive AGPL rights;
- For closed-source commercial use, you may only obtain a separate [commercial license](https://github.com/25Cloud/TuraIDC/blob/main/LICENSE-COMMERCIAL.md); **you may not remove the license and copyright notices yourself**.

### 3.3 Retain Attribution, License, and Repository Links

- Do not delete `LICENSE`, the copyright notices in source file headers, or the copyright and repository links in the front-end footer/sidebar;
- Do not package the TuraIDC brand as a "self-developed product" in external promotion (the commercial license does not include trademark authorization either);
- Modified versions should state prominently "modified based on TuraIDC" and retain the original project repository address.

### 3.4 Include the License and Source Code Access Method When Distributing

When distributing to others (including private delivery), provide at minimum all of the following at the same time: the full `LICENSE` text, the copyright notice, and the way to obtain the corresponding source code.

### 3.5 Dependency Licenses Must Also Be Compatible

Before introducing a new dependency, confirm that its license is compatible with AGPL:

- Generally fine: MIT, BSD, Apache-2.0, ISC, MPL-2.0 (MPL requires retaining file-level open source), GPL-3.0 (compatible with AGPL);
- Requires assessment: GPL-2.0-only (incompatible with AGPL), licenses with commercial restrictions, proprietary components, SSPL, etc.;
- Do not introduce dependencies that demand "no commercial use" or "mandatory paid licensing" — that will cause problems for your overall distribution.

## 4. How to Implement Section 13 (Site Operator Practice)

### 4.1 Deploying the Unmodified Official Version

**The least effort and most common compliant approach**: you do not need to build your own source code site; you only need to keep a visible entry point to the official repository on your site — the "Open Source Repository" and "Open Source Edition" entries in the official site footer were designed for exactly this. At the same time, retain the copyright notice and the `LICENSE` link.

> Note: if you only change configuration, the Logo, product data, or theme colors (admin panel config options), that generally does not count as modifying the program code; but if you modify template/component/style files, that counts as modification — see 4.2.

### 4.2 Deploying a Modified Version

You must provide users on your site with the **complete source code corresponding to the version actually running**; either one of the following is recommended:

1. **Public repository**: publish the modified version to a public repository (recommended: fork the official repository and retain the LICENSE and copyright notices), and place the repository link in the official site footer/about page;
2. **Source package download**: provide a source package on the site corresponding to the online version number (including build scripts, front-end source, and deployment configuration);
3. **Written provision method**: clearly tell users how to obtain the source code (e.g., email request, ticket), and ensure timely provision as required by AGPL.

> As long as the modified version is running online, you must provide the corresponding source code; "the source is on another branch / will be released later" does not satisfy the requirement.

### 4.3 Recommended Locations for Compliance Information

- Official site footer: "Open Source Repository" and "Open Source Edition" entries (built into this project's official site);
- About/footer copyright area: the `LICENSE` link, the copyright notice, and the "modified based on TuraIDC" statement (if applicable);
- For deployments under a commercial license: keep the authorization proof for inspection and avoid misrepresenting it as "self-developed" externally.

## 5. Commercial License: Who It Suits and What It Gives You

### 5.1 Applicable Scenarios

- **Sensitive industries**: government/enterprise, finance, healthcare, education, and other customers who do not permit publishing the source code or who must pass security compliance reviews;
- **Private delivery**: deploying for a customer while not handing the source code to the customer or the public;
- **Closed-source secondary development**: building customized features based on this project while not wanting to publish your own changes;
- **Internal compliance requirements**: corporate policy prohibits using AGPL software to provide external services.

If none of the above applies, the free AGPL edition is sufficient — **no purchase needed**.

### 5.2 Price and Terms

- **200 CNY/year**;
- Charged per **each organization, each production deployment**;
- Multiple deployments, group multiple entities, OEM/white-label, external SaaS sales, etc. are negotiable; educational/non-profit organizations may apply for a reduction;
- The final price and authorization quantity are governed by the order signed by both parties (see the [commercial license draft](https://github.com/25Cloud/TuraIDC/blob/main/LICENSE-COMMERCIAL.md)).

### 5.3 Rights Granted

- Closed-source deployment and operation, **exempt from the network source code provision obligation under AGPL Section 13**;
- Modifications and secondary development need not be published;
- Private delivery to your own customers within the authorized quantity.

### 5.4 Explicitly Not Included

- **No trademark authorization**: you may not promote using the TuraIDC name/Logo, nor imply an official partnership;
- **No resale/white-labeling**: you may not sell the software itself as a standalone product, as SaaS resale, as a template, as an image, or as a white-label product;
- **No source redistribution**: you may not give the source code to third parties under non-AGPL terms (they must obtain authorization separately).

## 6. How to Obtain the Source Code

TuraIDC is a fully open-source project; anyone can get the complete source code directly without purchasing a commercial license:

| Method                    | Address                                                                                       |
| ------------------------- | --------------------------------------------------------------------------------------------- |
| GitHub repository         | <https://github.com/25Cloud/TuraIDC>                                                          |
| Clone                     | `git clone https://github.com/25Cloud/TuraIDC.git`                                            |
| Release source package    | <https://github.com/25Cloud/TuraIDC/releases> (each version ships with a source archive)      |
| Official open-source page | the site's `/open-source` page (contains the above entry points and links to deployment docs) |

After pulling the source, deploy it according to [Deployment and Installation](deploy/index.md) or the [Generic Deployment Guide](deploy/generic.md); for upgrades see [Version Upgrade](maintain/upgrade.md).

## 7. How to Obtain Commercial Authorization

The process is currently manual (a formal sales channel is to be determined):

1. Open a new issue at <https://github.com/25Cloud/TuraIDC/issues> with the title "Commercial License Request", stating the organization name, number of deployments, and whether closed-source/private delivery is required;
2. Or email `contact@turaidc.com`;
3. The project team replies with a quote and order; after confirmation, sign and pay;
4. Obtain the authorization proof (number, validity period, deployment quantity) and keep it for inspection.

## 8. Pre-Launch Compliance Checklist

- [ ] `LICENSE`, the copyright notice, and the official repository link are retained, and the footer copyright information has not been deleted;
- [ ] Program unmodified: the site has an entry point to the official repository (the compliant approach for an unmodified deployment);
- [ ] Program modified: the site provides the complete source code corresponding to the online version (repository link or source package), including the build/deployment scripts;
- [ ] External distribution/private delivery: the recipient receives both the AGPL license and the source code access method;
- [ ] The licenses of newly introduced dependencies are compatible with AGPL and are noted in the change description;
- [ ] TuraIDC has not been packaged as a self-developed product, and its trademark has not been misappropriated;
- [ ] If a commercial license was purchased: the authorization is within its validity period, the deployment count does not exceed the authorization, and the proof is verifiable;
- [ ] If no commercial license was purchased: the two bottom lines "modifications must be open-sourced" and "closed-source distribution is ruled out" are accepted.

## 9. FAQ

**Q: I only deploy this site to sell cloud servers — do I need to pay?**
A: No. Free use under AGPL is sufficient; as long as you do not distribute it closed-source and you provide the source code to users after modification, you comply. Note the dividing line: **not modifying the program** (only changing site configuration, the Logo, product data) and serving external customers under your own brand does not trigger Section 13; if you **modify the program** and then provide services over the network, you must provide the corresponding source code to those users (or purchase a commercial license for closed-source use).

**Q: I do not change the code, only the admin panel configuration and the Logo — must I open source?**
A: The config options in the admin panel (site name, Logo, theme color, product data) do not count as modifying the program code, and you do not need to open-source your own business data; but retain the copyright notice and the official repository entry point. If you modify the template/component source code, that counts as modification.

**Q: The customer requires a private deployment and the source code may not be handed over — what then?**
A: This is a typical commercial license scenario. Either purchase a commercial license (charged per deployment), or provide the source code to the customer under AGPL.

**Q: What happens if the commercial license expires and I do not renew?**
A: There is a 30-day grace period after expiry, during which it remains valid; if the grace period ends without renewal, the authorization terminates and closed-source use may no longer continue, so you must renew or otherwise obtain an AGPL license (a commercial license does not automatically become an AGPL license).

**Q: Will the project team withdraw the open-source edition and turn it into closed source?**
A: No. Versions already released under AGPL are not withdrawn; the commercial license applies only to deployments that have obtained authorization.

**Q: Must plugins from secondary development be open-sourced?**
A: It depends on the relationship between the plugin and the main program. If a plugin is tightly integrated with the main program and constitutes a derivative work, treat it under AGPL; if it is invoked through an independent process or a standard interface, the boundary must be judged against the specific implementation, so consulting legal counsel is recommended.

**Q: I have already open-sourced my changes — do I still need to buy a commercial license?**
A: No. Compliant use under AGPL is sufficient.

## Related Documents

- [Deployment and Installation](deploy/index.md): choose a deployment method first; [Deployment and Scheduling](deploy/scheduling.md): scheduled tasks and the queue after go-live.
- [Version Upgrade](maintain/upgrade.md): the upgrade process and rollback.
- [Contributing Guide](../develop/contributing.md): submitting contributions and the CLA signing process.
- [AGPL-3.0-or-later full text](https://github.com/25Cloud/TuraIDC/blob/main/LICENSE): the sole licensing basis for the free path.
- [Commercial license draft](https://github.com/25Cloud/TuraIDC/blob/main/LICENSE-COMMERCIAL.md): the complete terms of the 200 CNY/year commercial license and the items pending legal review.
- [Contributor License Agreement CLA](https://github.com/25Cloud/TuraIDC/blob/main/CLA.md): the authorization source of the dual-licensing model.
