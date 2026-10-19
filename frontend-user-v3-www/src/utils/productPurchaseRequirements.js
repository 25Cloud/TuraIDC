import { t } from "@/locales";

const PURCHASE_REQUIREMENT_DEFINITIONS = Object.freeze({
  require_verification: {
    key: "require_verification",
    label: t("layout.user.realNameVerification"),
    badge: t("product.requirements.realNameVerification"),
    description: t("product.requirements.realNameVerificationHint"),
    unmetMessage: t("product.requirements.realNameVerificationBlocked"),
    route: "/client/verification",
    actionLabel: t("product.requirements.goToRealNameVerification"),
  },
  require_phone: {
    key: "require_phone",
    label: t("product.requirements.phoneBinding"),
    badge: t("product.requirements.phoneBindingRequired"),
    description: t("product.requirements.phoneBindingHint"),
    unmetMessage: t("product.requirements.phoneBindingBlocked"),
    route: "/client/profile",
    actionLabel: t("product.requirements.goToPhoneBinding"),
  },
});

function isRequirementEnabled(product, key) {
  return Boolean(product?.purchase_requires?.[key]);
}

function isRequirementSatisfied(requirement, user) {
  if (requirement.key === "require_verification") {
    return (
      Number(user?.is_verified || 0) === 1 ||
      Number(user?.verification_status || 0) === 2
    );
  }

  if (requirement.key === "require_phone") {
    return String(user?.phone || "").trim() !== "";
  }

  return false;
}

export function resolvePurchaseRequirementList(product) {
  return Object.values(PURCHASE_REQUIREMENT_DEFINITIONS).filter((item) =>
    isRequirementEnabled(product, item.key),
  );
}

export function resolvePurchaseRequirementSummary(product) {
  return resolvePurchaseRequirementList(product)
    .map((item) => item.description)
    .join(t("product.requirements.listSeparator"));
}

export function resolvePurchaseRequirementChecks(product, user, options = {}) {
  const hasToken = Boolean(options.hasToken);
  const loading = Boolean(options.loading);

  return resolvePurchaseRequirementList(product).map((item) => {
    let status = "missing";
    let statusLabel = t("product.requirements.statusIncomplete");
    let tone = "warning";

    if (!hasToken) {
      status = "login";
      statusLabel = t("product.requirements.loginRequired");
      tone = "info";
    } else if (loading && !user) {
      status = "loading";
      statusLabel = t("product.requirements.statusChecking");
      tone = "info";
    } else if (isRequirementSatisfied(item, user)) {
      status = "passed";
      statusLabel = t("product.requirements.statusPassed");
      tone = "success";
    }

    return {
      ...item,
      status,
      statusLabel,
      tone,
      passed: status === "passed",
      needsAction: status === "login" || status === "missing",
    };
  });
}

export function resolvePrimaryPurchaseRequirementAction(checks = []) {
  return checks.find((item) => item?.needsAction) || null;
}

export function resolveMissingPurchaseRequirements(product, user) {
  return resolvePurchaseRequirementList(product).filter(
    (item) => !isRequirementSatisfied(item, user),
  );
}
