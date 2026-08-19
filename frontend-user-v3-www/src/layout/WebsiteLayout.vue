<template>
  <div class="website-layout">
    <a class="skip-to-content" href="#main-content">跳到主内容</a>
    <header class="site-header" :class="{ scrolled: headerScrolled }">
      <div class="container header-bar">
        <router-link to="/" class="logo" :aria-label="appStore.siteName">
          <img
            :src="logoSrc"
            :alt="appStore.siteName"
            class="logo-image"
            @error="handleLogoError"
          />
          <span v-if="logoLoadFailed" class="logo-fallback">{{
            appStore.siteName
          }}</span>
        </router-link>

        <nav
          class="main-nav"
          @mouseleave="scheduleCloseMegaMenu()"
          aria-label="主导航"
        >
          <router-link
            v-for="item in navigationItems"
            :key="item.to"
            :to="item.to"
            class="main-nav__link"
            :class="{ 'is-active': isNavActive(item) }"
            @mouseenter="handleNavHover(item)"
          >
            <span>{{ item.label }}</span>
            <el-icon v-if="item.menuId" class="main-nav__arrow"
              ><ArrowDown
            /></el-icon>
          </router-link>
        </nav>

        <transition name="mega-menu">
          <div
            v-if="activeMenuId"
            class="mega-menu"
            @mouseenter="keepMegaMenu()"
            @mouseleave="scheduleCloseMegaMenu()"
          >
            <div class="mega-menu__inner container">
              <template v-if="activeMenuId === 'products'">
                <div class="mega-menu__types">
                  <button
                    v-for="type in navProductTypes"
                    :key="type.value"
                    type="button"
                    class="mega-type-btn"
                    :class="{ active: navActiveTypeValue === type.value }"
                    @mouseenter="navActivateType(type.value)"
                  >
                    <span class="mega-type-btn__label">{{ type.label }}</span>
                    <span class="mega-type-btn__count">{{
                      type.product_count
                    }}</span>
                  </button>
                </div>
                <div class="mega-menu__groups">
                  <router-link
                    v-for="group in navActiveGroups"
                    :key="group.id"
                    :to="resolveGroupPath(group)"
                    class="mega-group-card"
                  >
                    <span class="mega-group-card__name">{{ group.name }}</span>
                    <span class="mega-group-card__desc">{{
                      group.slogan || `${group.product_count} 款产品`
                    }}</span>
                  </router-link>
                  <div
                    v-if="!navActiveGroups.length && !navLoading"
                    class="mega-menu__empty"
                  >
                    暂无产品分类
                  </div>
                </div>
              </template>

              <template v-else-if="activeMenuId === 'notices'">
                <div class="mega-menu__types">
                  <button
                    type="button"
                    class="mega-type-btn"
                    :class="{ active: !navNoticesActiveCategory }"
                    @mouseenter="navNoticesActivateCategory(null)"
                  >
                    <span class="mega-type-btn__label">全部公告</span>
                    <span class="mega-type-btn__count">{{
                      navNoticesItems.length
                    }}</span>
                  </button>
                  <button
                    v-for="cat in navNoticesCategories"
                    :key="cat.label"
                    type="button"
                    class="mega-type-btn"
                    :class="{ active: navNoticesActiveCategory === cat.label }"
                    @mouseenter="navNoticesActivateCategory(cat.label)"
                  >
                    <span class="mega-type-btn__label">{{ cat.label }}</span>
                    <span class="mega-type-btn__count">{{ cat.count }}</span>
                  </button>
                  <router-link to="/notices" class="mega-type-more"
                    >查看全部 →</router-link
                  >
                </div>
                <div class="mega-menu__groups">
                  <router-link
                    v-for="item in navNoticesFiltered"
                    :key="item.id"
                    :to="`/notices/${item.id}`"
                    class="mega-group-card"
                  >
                    <span class="mega-group-card__name">{{ item.title }}</span>
                    <span class="mega-group-card__desc">{{
                      item.summary || formatDate(item.publish_at)
                    }}</span>
                  </router-link>
                  <div
                    v-if="!navNoticesFiltered.length && !navNoticesLoading"
                    class="mega-menu__empty"
                  >
                    暂无公告
                  </div>
                </div>
              </template>

              <template v-else-if="activeMenuId === 'help'">
                <div class="mega-menu__types">
                  <button
                    type="button"
                    class="mega-type-btn"
                    :class="{ active: !navHelpActiveCategory }"
                    @mouseenter="navHelpActivateCategory(null)"
                  >
                    <span class="mega-type-btn__label">全部文档</span>
                    <span class="mega-type-btn__count">{{
                      navHelpItems.length
                    }}</span>
                  </button>
                  <button
                    v-for="cat in navHelpCategories"
                    :key="cat.label"
                    type="button"
                    class="mega-type-btn"
                    :class="{ active: navHelpActiveCategory === cat.label }"
                    @mouseenter="navHelpActivateCategory(cat.label)"
                  >
                    <span class="mega-type-btn__label">{{ cat.label }}</span>
                    <span class="mega-type-btn__count">{{ cat.count }}</span>
                  </button>
                  <router-link to="/help" class="mega-type-more"
                    >查看全部 →</router-link
                  >
                </div>
                <div class="mega-menu__groups">
                  <router-link
                    v-for="item in navHelpFiltered"
                    :key="item.id"
                    :to="`/help/${item.id}`"
                    class="mega-group-card"
                  >
                    <span class="mega-group-card__name">{{ item.title }}</span>
                    <span class="mega-group-card__desc">{{
                      item.summary || "查看详情"
                    }}</span>
                  </router-link>
                  <div
                    v-if="!navHelpFiltered.length && !navHelpLoading"
                    class="mega-menu__empty"
                  >
                    暂无文档
                  </div>
                </div>
              </template>

              <template v-else-if="activeMenuId === 'about'">
                <div class="mega-menu__types">
                  <div class="mega-type-heading">帮助中心</div>
                  <div class="mega-type-desc">快速获取联系方式与常用入口</div>
                </div>
                <div class="mega-menu__groups">
                  <template v-for="link in aboutQuickLinks" :key="link.to">
                    <a
                      v-if="isConsolePath(link.to)"
                      :href="consoleUrl(link.to)"
                      class="mega-group-card"
                    >
                      <span class="mega-group-card__name">{{
                        link.title
                      }}</span>
                      <span class="mega-group-card__desc">{{ link.desc }}</span>
                    </a>
                    <router-link v-else :to="link.to" class="mega-group-card">
                      <span class="mega-group-card__name">{{
                        link.title
                      }}</span>
                      <span class="mega-group-card__desc">{{ link.desc }}</span>
                    </router-link>
                  </template>
                </div>
              </template>
            </div>
          </div>
        </transition>

        <div class="header-actions">
          <div
            v-if="userStore.isLoggedIn"
            ref="userMenuTriggerRef"
            class="user-menu"
          >
          <button
            type="button"
            class="header-user-trigger"
            :class="{ 'is-active': userMenuOpen }"
            aria-label="用户菜单"
            :aria-expanded="userMenuOpen"
            @click="toggleUserMenu"
          >
            <span class="user-avatar-initial">{{ userAvatarInitial }}</span>
            <span class="header-user-name">{{ userDisplayName }}</span>
            <el-icon class="header-user-arrow"><ArrowDown /></el-icon>
          </button>
          <transition name="user-menu-fade">
            <div v-if="userMenuOpen" class="user-menu-panel" role="menu">
              <button
                type="button"
                role="menuitem"
                class="user-menu-item"
                @click="onUserMenuCommand(consoleUrl('/client/dashboard'))"
              >
                <el-icon><Monitor /></el-icon>控制台
              </button>
              <button
                type="button"
                role="menuitem"
                class="user-menu-item"
                @click="onUserMenuCommand(consoleUrl('/client/services'))"
              >
                <el-icon><Box /></el-icon>我的服务
              </button>
              <div class="user-menu-divider"></div>
              <button
                type="button"
                role="menuitem"
                class="user-menu-item"
                @click="onUserMenuCommand(consoleUrl('/client/profile'))"
              >
                <el-icon><User /></el-icon>个人资料
              </button>
              <button
                type="button"
                role="menuitem"
                class="user-menu-item"
                @click="onUserMenuCommand(consoleUrl('/client/recharge'))"
              >
                <el-icon><Wallet /></el-icon>账户充值
              </button>
              <div class="user-menu-divider"></div>
              <button
                type="button"
                role="menuitem"
                class="user-menu-item is-danger"
                @click="onUserMenuCommand('logout')"
              >
                <el-icon><CircleClose /></el-icon>退出登录
              </button>
            </div>
          </transition>
          </div>
          <template v-if="!userStore.isLoggedIn">
            <a :href="consoleUrl('/client/login')" class="header-link">登录</a>
            <a :href="consoleUrl('/client/register')" class="header-register"
              >免费注册</a
            >
          </template>
        </div>

        <div
          v-if="userStore.isLoggedIn"
          ref="mobileUserMenuTriggerRef"
          class="mobile-user-menu"
        >
          <button
            type="button"
            class="mobile-user-icon is-avatar"
            aria-label="用户菜单"
            :aria-expanded="mobileUserMenuOpen"
            @click="toggleMobileUserMenu"
          >
            <span class="user-avatar-initial">{{ userAvatarInitial }}</span>
          </button>
          <transition name="user-menu-fade">
            <div v-if="mobileUserMenuOpen" class="user-menu-panel" role="menu">
              <button
                type="button"
                role="menuitem"
                class="user-menu-item"
                @click="onUserMenuCommand(consoleUrl('/client/dashboard'))"
              >
                <el-icon><Monitor /></el-icon>控制台
              </button>
              <button
                type="button"
                role="menuitem"
                class="user-menu-item"
                @click="onUserMenuCommand(consoleUrl('/client/services'))"
              >
                <el-icon><Box /></el-icon>我的服务
              </button>
              <div class="user-menu-divider"></div>
              <button
                type="button"
                role="menuitem"
                class="user-menu-item"
                @click="onUserMenuCommand(consoleUrl('/client/profile'))"
              >
                <el-icon><User /></el-icon>个人资料
              </button>
              <button
                type="button"
                role="menuitem"
                class="user-menu-item"
                @click="onUserMenuCommand(consoleUrl('/client/recharge'))"
              >
                <el-icon><Wallet /></el-icon>账户充值
              </button>
              <div class="user-menu-divider"></div>
              <button
                type="button"
                role="menuitem"
                class="user-menu-item is-danger"
                @click="onUserMenuCommand('logout')"
              >
                <el-icon><CircleClose /></el-icon>退出登录
              </button>
            </div>
          </transition>
        </div>
        <a v-else :href="consoleUrl('/client/login')" class="mobile-user-icon">
          <el-icon :size="20"><User /></el-icon>
        </a>
        <button
          type="button"
          class="mobile-menu-toggle"
          :class="{ 'is-open': mobileNavVisible }"
          :aria-label="mobileNavVisible ? '关闭菜单' : '打开菜单'"
          @click="mobileNavVisible = !mobileNavVisible"
        >
          <span class="hamburger">
            <span class="hamburger-line" />
            <span class="hamburger-line" />
            <span class="hamburger-line" />
          </span>
        </button>
      </div>
    </header>

    <transition name="mobile-menu-mask">
      <div
        v-if="isMobile && mobileNavVisible"
        class="mobile-menu-mask"
        @click="mobileNavVisible = false"
      />
    </transition>

    <transition name="mobile-menu-panel">
      <div v-if="isMobile && mobileNavVisible" class="mobile-menu-panel">
        <div class="mobile-menu-two-col">
          <div class="mobile-menu-col mobile-menu-col--left">
            <template
              v-for="item in navigationItems"
              :key="`mobile-${item.to}`"
            >
              <a
                v-if="item.menuId"
                class="mobile-first-level-item"
                :class="{ active: mobileActiveFirstLevel === item.menuId }"
                @click="mobileActiveFirstLevel = item.menuId"
              >
                <span class="mobile-first-level-label">{{ item.label }}</span>
              </a>
            </template>
            <div class="mobile-left-bottom">
              <template v-if="userStore.isLoggedIn">
                <a
                  :href="consoleUrl('/client/dashboard')"
                  class="mobile-left-login-btn"
                  >控制台</a
                >
              </template>
              <template v-else>
                <a
                  :href="consoleUrl('/client/login')"
                  class="mobile-left-login-btn"
                  >登录 / 注册</a
                >
              </template>
            </div>
          </div>

          <div class="mobile-menu-col mobile-menu-col--right">
            <template v-if="mobileActiveFirstLevel === 'products'">
              <template v-if="navProductTypes.length">
                <div
                  v-for="type in navProductTypes"
                  :key="`m-type-${type.value}`"
                  class="mobile-second-group"
                >
                  <button
                    type="button"
                    class="mobile-second-level-item"
                    :class="{ active: mobileExpandedType === type.value }"
                    @click="
                      mobileExpandedType =
                        mobileExpandedType === type.value ? '' : type.value;
                      navLoadGroupsForType(type.value);
                    "
                  >
                    <span class="mobile-second-level-label">{{
                      type.label
                    }}</span>
                    <span class="mobile-second-level-right">
                      <svg
                        class="mobile-second-level-arrow"
                        :class="{ expanded: mobileExpandedType === type.value }"
                        viewBox="0 0 10 6"
                        fill="none"
                        width="10"
                        height="6"
                        aria-hidden="true"
                      >
                        <path
                          d="M1 1l4 4 4-4"
                          stroke="currentColor"
                          stroke-width="1.5"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                      </svg>
                    </span>
                  </button>
                  <div
                    v-if="
                      mobileExpandedType === type.value &&
                      navGetGroupsForType(type.value).length
                    "
                    class="mobile-third-level-list"
                  >
                    <router-link
                      v-for="group in navGetGroupsForType(type.value)"
                      :key="`m-group-${group.id}`"
                      :to="resolveGroupPath(group)"
                      class="mobile-third-level-item"
                      @click="closeMobileMenu()"
                    >
                      {{ group.name }}
                    </router-link>
                  </div>
                </div>
              </template>
              <div v-else-if="navLoading" class="mobile-menu-loading">
                加载中…
              </div>
              <div v-else class="mobile-menu-empty">暂无产品分类</div>
            </template>

            <template v-else-if="mobileActiveFirstLevel === 'notices'">
              <template v-if="navNoticesCategories.length">
                <div
                  v-for="cat in navNoticesCategories"
                  :key="`m-ncat-${cat.label}`"
                  class="mobile-second-group"
                >
                  <button
                    type="button"
                    class="mobile-second-level-item"
                    :class="{ active: navNoticesActiveCategory === cat.label }"
                    @click="navNoticesActivateCategory(cat.label)"
                  >
                    <span class="mobile-second-level-label">{{
                      cat.label
                    }}</span>
                    <span class="mobile-second-level-right">
                      <svg
                        class="mobile-second-level-arrow"
                        :class="{
                          expanded: navNoticesActiveCategory === cat.label,
                        }"
                        viewBox="0 0 10 6"
                        fill="none"
                        width="10"
                        height="6"
                        aria-hidden="true"
                      >
                        <path
                          d="M1 1l4 4 4-4"
                          stroke="currentColor"
                          stroke-width="1.5"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                      </svg>
                    </span>
                  </button>
                  <div
                    v-if="
                      navNoticesActiveCategory === cat.label &&
                      navNoticesFiltered.length
                    "
                    class="mobile-third-level-list"
                  >
                    <router-link
                      v-for="item in navNoticesFiltered"
                      :key="`m-ni-${item.id}`"
                      :to="`/notices/${item.id}`"
                      class="mobile-third-level-item"
                      @click="closeMobileMenu()"
                    >
                      {{ item.title }}
                    </router-link>
                  </div>
                </div>
                <router-link
                  to="/notices"
                  class="mobile-view-all"
                  @click="closeMobileMenu()"
                  >查看全部公告 →</router-link
                >
              </template>
              <div v-else-if="navNoticesLoading" class="mobile-menu-loading">
                加载中…
              </div>
              <div v-else class="mobile-menu-empty">暂无公告</div>
            </template>

            <template v-else-if="mobileActiveFirstLevel === 'help'">
              <template v-if="navHelpCategories.length">
                <div
                  v-for="cat in navHelpCategories"
                  :key="`m-hcat-${cat.label}`"
                  class="mobile-second-group"
                >
                  <button
                    type="button"
                    class="mobile-second-level-item"
                    :class="{ active: navHelpActiveCategory === cat.label }"
                    @click="navHelpActivateCategory(cat.label)"
                  >
                    <span class="mobile-second-level-label">{{
                      cat.label
                    }}</span>
                    <span class="mobile-second-level-right">
                      <svg
                        class="mobile-second-level-arrow"
                        :class="{
                          expanded: navHelpActiveCategory === cat.label,
                        }"
                        viewBox="0 0 10 6"
                        fill="none"
                        width="10"
                        height="6"
                        aria-hidden="true"
                      >
                        <path
                          d="M1 1l4 4 4-4"
                          stroke="currentColor"
                          stroke-width="1.5"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                      </svg>
                    </span>
                  </button>
                  <div
                    v-if="
                      navHelpActiveCategory === cat.label &&
                      navHelpFiltered.length
                    "
                    class="mobile-third-level-list"
                  >
                    <router-link
                      v-for="item in navHelpFiltered"
                      :key="`m-hi-${item.id}`"
                      :to="`/help/${item.id}`"
                      class="mobile-third-level-item"
                      @click="closeMobileMenu()"
                    >
                      {{ item.title }}
                    </router-link>
                  </div>
                </div>
                <router-link
                  to="/help"
                  class="mobile-view-all"
                  @click="closeMobileMenu()"
                  >查看全部文档 →</router-link
                >
              </template>
              <div v-else-if="navHelpLoading" class="mobile-menu-loading">
                加载中…
              </div>
              <div v-else class="mobile-menu-empty">暂无文档</div>
            </template>

            <template v-else-if="mobileActiveFirstLevel === 'about'">
              <template
                v-for="link in aboutQuickLinks"
                :key="`m-about-${link.to}`"
              >
                <a
                  v-if="isConsolePath(link.to)"
                  :href="consoleUrl(link.to)"
                  class="mobile-third-level-item mobile-third-level-item--link"
                >
                  {{ link.title }}
                </a>
                <router-link
                  v-else
                  :to="link.to"
                  class="mobile-third-level-item mobile-third-level-item--link"
                  @click="closeMobileMenu()"
                >
                  {{ link.title }}
                </router-link>
              </template>
            </template>
          </div>
        </div>
      </div>
    </transition>

    <main id="main-content" class="site-main">
      <router-view v-slot="{ Component }">
        <transition name="page-fade" mode="out-in">
          <component :is="Component" />
        </transition>
      </router-view>
    </main>

    <footer v-if="!hideFooter" class="site-footer">
      <div class="container">
        <div class="footer-top">
          <div class="footer-brand">
            <div class="footer-logo">
              <img
                :src="logoSrc"
                :alt="appStore.siteName"
                class="footer-logo-image"
                @error="handleFooterLogoError"
              />
              <span v-if="footerLogoLoadFailed" class="footer-logo-fallback">{{
                appStore.siteName
              }}</span>
            </div>
            <p class="footer-brand__desc">
              为企业与开发者提供稳定、安全、高性价比的云计算与 IDC 服务。
            </p>
            <ul class="footer-contact">
              <li v-for="item in supportContacts" :key="item.key">
                <span class="footer-contact__label">{{ item.label }}</span>
                <a
                  v-if="item.key === 'qq-group' && appStore.supportGroupLink"
                  :href="appStore.supportGroupLink"
                  class="footer-contact__value footer-contact__link"
                  target="_blank"
                  rel="noopener noreferrer"
                >{{ item.value }}</a>
                <span v-else class="footer-contact__value">{{ item.value }}</span>
              </li>
            </ul>
          </div>

          <div class="footer-columns">
            <div class="footer-col">
              <h4>产品</h4>
              <router-link
                v-for="item in seoLandingFooterLinks"
                :key="item.to"
                :to="item.to"
              >
                {{ item.label }}
              </router-link>
            </div>

            <div class="footer-col">
              <h4>解决方案</h4>
              <router-link to="/products">电商行业</router-link>
              <router-link to="/products">游戏行业</router-link>
              <router-link to="/products">金融行业</router-link>
              <router-link to="/products">出海业务</router-link>
            </div>

            <div class="footer-col">
              <h4>支持</h4>
              <router-link to="/notices">站点公告</router-link>
              <router-link to="/help">帮助中心</router-link>
              <a :href="consoleUrl('/client/tickets')">工单系统</a>
              <router-link to="/about">关于我们</router-link>
            </div>

            <div class="footer-col">
              <h4>账户</h4>
              <a :href="consoleUrl('/client/register')">免费注册</a>
              <a :href="consoleUrl('/client/login')">账户登录</a>
              <a :href="consoleUrl('/client/dashboard')">进入控制台</a>
              <a :href="consoleUrl('/client/verification')">实名认证</a>
            </div>
          </div>
        </div>

        <div class="footer-bottom">
          <p>
            &copy; {{ new Date().getFullYear() }} {{ appStore.siteName }}. All
            rights reserved.
          </p>
          <p
            v-if="appStore.valueAddedLicense || appStore.icpRecord"
            class="footer-bottom__meta"
          >
            <span v-if="appStore.valueAddedLicense"
              >增值电信业务经营许可证：{{ appStore.valueAddedLicense }}</span
            >
            <span v-if="appStore.icpRecord"
              >ICP 备案号：{{ appStore.icpRecord }}</span
            >
          </p>
        </div>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute } from "vue-router";
import { ElIcon } from "element-plus/es/components/icon/index.mjs";
import {
  ArrowDown,
  Box,
  CircleClose,
  Monitor,
  User,
  Wallet,
} from "@element-plus/icons-vue";
import { useAppStore } from "@/stores/app";
import { useUserStore } from "@/stores/user";
import { buildSupportContacts } from "@/data/supportContacts";
import { seoLandingFooterLinks } from "@/data/seoLandingMeta";
import { buildConsoleUrl, isConsolePath } from "@/utils/consoleUrl";
import { useNavProductMenu } from "./useNavProductMenu";
import { useNavContentMenu } from "./useNavContentMenu";

const route = useRoute();
const appStore = useAppStore();
const userStore = useUserStore();

// 登录态恢复放到 onMounted，避免 setup 顶层产生路由跳转副作用
// 跨端口登录传递仍走 query _token（与控制台 v4-console 约定保持一致），收到后立即持久化并从 URL 剥离
onMounted(() => {
  // 仅在本地已有 token 时才拉取用户信息，避免未登录时触发 401
  userStore.fetchUserInfo().catch(() => {});
});

const navProductMenu = useNavProductMenu();
const {
  productTypes: navProductTypes,
  activeTypeValue: navActiveTypeValue,
  activeGroups: navActiveGroups,
  getGroupsForType: navGetGroupsForType,
  loading: navLoading,
  activateType: navActivateType,
  loadGroupsForType: navLoadGroupsForType,
  init: navProductInit,
} = navProductMenu;
const navNoticesMenu = useNavContentMenu("notice");
const {
  items: navNoticesItems,
  loading: navNoticesLoading,
  categories: navNoticesCategories,
  activeCategory: navNoticesActiveCategory,
  filteredItems: navNoticesFiltered,
  activateCategory: navNoticesActivateCategory,
} = navNoticesMenu;
const navHelpMenu = useNavContentMenu("help");
const {
  items: navHelpItems,
  loading: navHelpLoading,
  categories: navHelpCategories,
  activeCategory: navHelpActiveCategory,
  filteredItems: navHelpFiltered,
  activateCategory: navHelpActivateCategory,
} = navHelpMenu;

const mobileNavVisible = ref(false);
const mobileActiveFirstLevel = ref("products");
const mobileExpandedType = ref("");
const headerScrolled = ref(false);
const isMobile = ref(
  typeof window === "undefined" ? false : window.innerWidth <= 960,
);
const logoLoadFailed = ref(false);
const footerLogoLoadFailed = ref(false);

const userAvatarInitial = computed(() => {
  if (!userStore.isLoggedIn) return "";
  const info = userStore.info;
  const name = (
    info?.display_name ||
    info?.nickname ||
    info?.email ||
    ""
  ).trim();
  return name ? name.charAt(0).toUpperCase() : "U";
});

const userDisplayName = computed(() => {
  if (!userStore.isLoggedIn) return "";
  const info = userStore.info;
  return (info?.display_name || info?.nickname || info?.email || "").trim();
});

function handleUserMenuCommand(command) {
  if (command === "logout") {
    userStore.logout().then(() => {
      window.location.reload();
    });
  } else {
    window.location.href = command;
  }
}

// 轻量自定义用户下拉（替代 el-dropdown，避免 element-plus popper 机制进首屏）
const userMenuOpen = ref(false);
const mobileUserMenuOpen = ref(false);
const userMenuTriggerRef = ref(null);
const mobileUserMenuTriggerRef = ref(null);

function toggleUserMenu() {
  userMenuOpen.value = !userMenuOpen.value;
}

function toggleMobileUserMenu() {
  mobileUserMenuOpen.value = !mobileUserMenuOpen.value;
}

function onUserMenuCommand(command) {
  userMenuOpen.value = false;
  mobileUserMenuOpen.value = false;
  handleUserMenuCommand(command);
}

function closeUserMenusOnOutside(event) {
  const desktopRoot = userMenuTriggerRef.value;
  const mobileRoot = mobileUserMenuTriggerRef.value;
  const insideDesktop = desktopRoot && desktopRoot.contains(event.target);
  const insideMobile = mobileRoot && mobileRoot.contains(event.target);
  if (!insideDesktop && !insideMobile) {
    userMenuOpen.value = false;
    mobileUserMenuOpen.value = false;
  }
}

function closeUserMenusOnEscape(event) {
  if (event.key === "Escape") {
    userMenuOpen.value = false;
    mobileUserMenuOpen.value = false;
  }
}

const activeMenuId = ref(null);
let megaMenuCloseTimer = null;
let megaMenuOpenTimer = null;
const MEGA_MENU_OPEN_DELAY = 120;

function openMegaMenu(menuId) {
  clearTimeout(megaMenuCloseTimer);
  clearTimeout(megaMenuOpenTimer);
  activeMenuId.value = menuId;
  if (menuId === "products") {
    navProductInit();
  } else if (menuId === "notices") {
    navNoticesMenu.init();
  } else if (menuId === "help") {
    navHelpMenu.init();
  }
}

function handleNavHover(item) {
  if (item.menuId) {
    const suppressOnActive = item.menuId !== "about";
    if (suppressOnActive && isNavActive(item)) {
      scheduleCloseMegaMenu();
      return;
    }
    // 延迟打开，避免鼠标快速滑过导航时触发菜单与接口拉取
    clearTimeout(megaMenuOpenTimer);
    megaMenuOpenTimer = setTimeout(() => {
      openMegaMenu(item.menuId);
    }, MEGA_MENU_OPEN_DELAY);
  } else {
    scheduleCloseMegaMenu();
  }
}

function keepMegaMenu() {
  clearTimeout(megaMenuCloseTimer);
  clearTimeout(megaMenuOpenTimer);
}

function scheduleCloseMegaMenu() {
  clearTimeout(megaMenuCloseTimer);
  clearTimeout(megaMenuOpenTimer);
  megaMenuCloseTimer = setTimeout(() => {
    activeMenuId.value = null;
  }, 180);
}

function resolveGroupPath(group) {
  const typeCode = String(group.first_product_group_code || "");
  const groupId = Number(group.id || 0);
  if (!typeCode || !groupId) {
    return "/products";
  }
  return `/products?type=${encodeURIComponent(typeCode)}&group=${groupId}`;
}

function formatDate(value) {
  if (!value) return "";
  const str = String(value);
  return str.slice(0, 10);
}

// 产品选购页隐藏页脚，避免干扰购买流程
const hideFooter = computed(() => {
  const path = route.path;
  return path === "/products" || path.startsWith("/products/");
});

const navigationItems = [
  { to: "/", label: "首页", match: ["WwwHome"] },
  {
    to: "/products",
    label: "产品",
    match: [
      "WwwProducts",
      "WwwProductsPurchase",
      "WwwProductsPurchaseWithChild",
      "WwwProductDetail",
    ],
    menuId: "products",
  },
  {
    to: "/notices",
    label: "公告",
    match: ["WwwNotices", "WwwNoticeDetail"],
    menuId: "notices",
  },
  {
    to: "/help",
    label: "帮助",
    match: ["WwwHelp", "WwwHelpDetail"],
    menuId: "help",
  },
  { to: "/about", label: "其他", match: ["WwwAbout"], menuId: "about" },
];

const aboutQuickLinks = [
  { to: "/about", title: "关于我们", desc: "企业简介、发展愿景与服务承诺" },
  {
    to: "/client/tickets",
    title: "工单支持",
    desc: "提交售后工单，获得 1v1 响应",
  },
  { to: "/help", title: "帮助文档", desc: "常见问题与使用指南" },
  { to: "/notices", title: "公告动态", desc: "产品更新、活动与维护通知" },
];

const logoSrc = computed(() => appStore.siteLogo || "/branding/logo.png");
const supportContacts = computed(() =>
  buildSupportContacts({
    serviceQqGroup: appStore.serviceQqGroup,
    serviceEmail: appStore.serviceEmail,
    serviceHours: appStore.serviceHours,
  }),
);

function consoleUrl(path) {
  return buildConsoleUrl(path);
}

function isNavActive(item) {
  if (item.menuId === "products" && route.meta?.seoLanding) {
    return true;
  }
  return item.match.includes(route.name);
}

function handleLogoError() {
  logoLoadFailed.value = true;
}

function handleFooterLogoError() {
  footerLogoLoadFailed.value = true;
}

watch(logoSrc, () => {
  logoLoadFailed.value = false;
  footerLogoLoadFailed.value = false;
});

function closeMobileMenu() {
  mobileNavVisible.value = false;
}

let scrollRaf = null;
function handleScroll() {
  // rAF 合并，一帧只处理一次滚动
  if (scrollRaf) return;
  scrollRaf = requestAnimationFrame(() => {
    scrollRaf = null;
    if (typeof window === "undefined") return;
    headerScrolled.value = window.scrollY > 8;
  });
}

function handleResize() {
  if (typeof window === "undefined") return;
  const nextMobile = window.innerWidth <= 960;
  if (nextMobile === isMobile.value) return;
  isMobile.value = nextMobile;
  if (!isMobile.value) {
    closeMobileMenu();
  }
}

watch(
  () => route.fullPath,
  () => {
    closeMobileMenu();
    activeMenuId.value = null;
    mobileExpandedType.value = "";
  },
);

watch(mobileNavVisible, (visible) => {
  if (visible) {
    document.body.style.overflow = "hidden";
    mobileExpandedType.value = "";
    navProductInit();
    navNoticesMenu.init();
    navHelpMenu.init();
  } else {
    document.body.style.overflow = "";
  }
});

onMounted(() => {
  handleScroll();
  handleResize();
  window.addEventListener("scroll", handleScroll, { passive: true });
  window.addEventListener("resize", handleResize, { passive: true });
  document.addEventListener("pointerdown", closeUserMenusOnOutside);
  document.addEventListener("keydown", closeUserMenusOnEscape);
});

onBeforeUnmount(() => {
  window.removeEventListener("scroll", handleScroll);
  window.removeEventListener("resize", handleResize);
  document.removeEventListener("pointerdown", closeUserMenusOnOutside);
  document.removeEventListener("keydown", closeUserMenusOnEscape);
  if (scrollRaf) cancelAnimationFrame(scrollRaf);
  scrollRaf = null;
  document.body.style.overflow = "";
  clearTimeout(megaMenuCloseTimer);
  clearTimeout(megaMenuOpenTimer);
});
</script>

<style scoped lang="scss">
.website-layout {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: $bg-color;
}

.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.site-header {
  position: sticky;
  top: 0;
  z-index: 100;
  height: 64px;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-bottom: 1px solid transparent;
  transition:
    border-color $motion-base ease,
    box-shadow $motion-base ease;
}

.site-header.scrolled {
  border-bottom-color: $divider-color;
  box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
}

.header-bar {
  display: flex;
  align-items: center;
  height: 100%;
  gap: 0;
}

.logo {
  display: inline-flex;
  align-items: center;
  text-decoration: none;
  flex-shrink: 0;
  min-width: 0;
  width: 148px;
  height: 64px;
}

.logo-image {
  display: block;
  width: 100%;
  max-width: 148px;
  height: 32px;
  object-fit: contain;
  object-position: left center;
}

.logo-fallback {
  display: flex;
  align-items: center;
  height: 32px;
  padding: 0 8px;
  background: $color-primary;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  border-radius: 6px;
  white-space: nowrap;
}

.main-nav {
  display: flex;
  align-items: center;
  gap: 0;
  margin-left: 32px;
}

.main-nav__link {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 0 20px;
  height: 64px;
  color: #374151;
  font-size: 14px;
  font-weight: 500;
  letter-spacing: 0.01em;
  text-decoration: none;
  transition: color 0.16s ease;
}

.main-nav__link::after {
  content: "";
  position: absolute;
  left: 50%;
  bottom: 12px;
  width: 20px;
  height: 2px;
  border-radius: 999px;
  background: $color-primary;
  opacity: 0;
  transform: translateX(-50%) scaleX(0.6);
  transition:
    opacity 0.18s ease,
    transform 0.18s ease;
}

.main-nav__link:hover {
  color: $color-primary;
}

.main-nav__link.is-active {
  color: $color-primary;
  font-weight: 600;
}

.main-nav__link.is-active::after {
  opacity: 1;
  transform: translateX(-50%) scaleX(1);
}

.main-nav__arrow {
  font-size: 11px;
  color: inherit;
  opacity: 0.5;
  transition: opacity 0.16s ease;
}

.main-nav__link:hover .main-nav__arrow {
  opacity: 1;
}

.mega-menu {
  position: fixed;
  top: 64px;
  left: 0;
  right: 0;
  z-index: 90;
  background: #ffffff;
  border-bottom: 1px solid $divider-color;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
  max-height: calc(100vh - 80px);
  overflow-y: auto;
}

.mega-menu__inner {
  display: grid;
  grid-template-columns: 200px minmax(0, 1fr);
  min-height: 320px;
}

.mega-menu__types {
  display: flex;
  flex-direction: column;
  padding: 16px 0;
  border-right: 1px solid $divider-color;
  background: #f8fafc;
}

.mega-type-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 10px 20px;
  border: none;
  background: transparent;
  color: $text-color-secondary;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition:
    color 0.14s ease,
    background 0.14s ease;
  text-align: left;
}

.mega-type-btn:hover {
  color: $color-primary;
  background: rgba(22, 93, 255, 0.06);
}

.mega-type-btn.active {
  color: $color-primary;
  font-weight: 600;
  background: #ffffff;
  border-right: 2px solid $color-primary;
}

.mega-type-btn__count {
  font-size: 11px;
  color: $text-color-placeholder;
  font-weight: 400;
}

.mega-type-btn.active .mega-type-btn__count {
  color: $color-primary;
}

.mega-type-more {
  display: block;
  padding: 12px 20px 0;
  margin-top: auto;
  font-size: 12px;
  color: $color-primary;
  text-decoration: none;
  font-weight: 500;
  transition: color 0.14s ease;
}

.mega-type-more:hover {
  color: #0e4fcc;
}

.mega-type-heading {
  padding: 16px 20px 4px;
  font-size: 15px;
  font-weight: 600;
  color: $text-color-primary;
  line-height: 1.4;
}

.mega-type-desc {
  padding: 0 20px 12px;
  font-size: 12px;
  color: $text-color-placeholder;
  line-height: 1.5;
}

.mega-menu__groups {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0;
  padding: 8px 0;
  align-content: start;
}

.mega-group-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 14px 20px;
  text-decoration: none;
  transition: background 0.14s ease;
}

.mega-group-card:hover {
  background: rgba(22, 93, 255, 0.03);
}

.mega-group-card__name {
  color: $text-color-primary;
  font-size: 13px;
  font-weight: 600;
  transition: color 0.14s ease;
}

.mega-group-card:hover .mega-group-card__name {
  color: $color-primary;
}

.mega-group-card__desc {
  color: $text-color-placeholder;
  font-size: 12px;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.mega-menu__empty {
  grid-column: 1 / -1;
  padding: 40px 20px;
  color: $text-color-placeholder;
  font-size: 13px;
  text-align: center;
}

.mega-menu-enter-active {
  transition:
    opacity 0.18s ease-out,
    transform 0.18s ease-out;
}

.mega-menu-leave-active {
  transition:
    opacity 0.14s ease,
    transform 0.14s ease;
}

.mega-menu-enter-from,
.mega-menu-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  margin-left: auto;
}

.header-user-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  color: #374151;
  text-decoration: none;
  transition:
    color 0.16s ease,
    background 0.16s ease;
}

.header-user-trigger {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 12px 4px 4px;
  border: none;
  background: transparent;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.16s ease;
  text-decoration: none;
  color: #1f2937;

  .user-avatar-initial {
    width: 34px;
    height: 34px;
    background: #165dff;
    color: #ffffff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    line-height: 1;
  }

  .header-user-name {
    font-size: 13px;
    font-weight: 500;
    color: #374151;
    white-space: nowrap;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &:hover {
    background: rgba(22, 93, 255, 0.06);
  }

  .header-user-arrow {
    font-size: 12px;
    color: #9ca3af;
    transition:
      transform 0.25s ease,
      color 0.16s ease;
  }

  &.is-active .header-user-arrow,
  &:hover .header-user-arrow {
    color: #374151;
  }
}

// 轻量自定义用户下拉（替代 el-dropdown，避免 element-plus popper 机制进首屏）
.user-menu,
.mobile-user-menu {
  position: relative;
}

.user-menu-panel {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 60;
  min-width: 180px;
  padding: 6px;
  background: $bg-color-card;
  border: 1px solid $border-color;
  border-radius: 12px;
  box-shadow: $shadow-lg;
}

.user-menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 9px 12px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: $text-color-primary;
  font-size: 14px;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
  transition:
    background $motion-fast ease,
    color $motion-fast ease;

  .el-icon {
    font-size: 16px;
    color: $text-color-placeholder;
    transition: color $motion-fast ease;
  }

  &:hover {
    background: rgba(22, 93, 255, 0.06);
    color: $color-primary;

    .el-icon {
      color: $color-primary;
    }
  }

  &.is-danger {
    color: $color-danger;

    .el-icon {
      color: $color-danger;
    }
  }

  &.is-danger:hover {
    background: rgba(240, 68, 56, 0.08);
  }
}

.user-menu-divider {
  height: 1px;
  margin: 6px 4px;
  background: $divider-color;
}

.user-menu-fade-enter-active,
.user-menu-fade-leave-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
  transform-origin: top right;
}

.user-menu-fade-enter-from,
.user-menu-fade-leave-to {
  opacity: 0;
  transform: scaleY(0.85);
}

.mobile-user-menu {
  display: none;

  .mobile-user-icon {
    border: none;
    padding: 0;
    font: inherit;
    cursor: pointer;
  }
}

@media (max-width: 960px) {
  .mobile-user-menu {
    display: block;
  }
}

.header-user-icon.is-avatar {
  background: #165dff;
  color: #ffffff;
}

.header-user-icon:hover {
  color: $color-primary;
  background: rgba(22, 93, 255, 0.06);
}

.header-user-icon.is-avatar:hover {
  color: #ffffff;
  background: #0e4fcc;
  box-shadow: 0 2px 8px rgba(22, 93, 255, 0.3);
}

.user-avatar-initial {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  font-size: 14px;
  font-weight: 600;
  line-height: 1;
  letter-spacing: 0;
}

.header-link {
  display: inline-flex;
  align-items: center;
  height: 34px;
  padding: 0 14px;
  color: #374151;
  font-size: 13px;
  font-weight: 500;
  text-decoration: none;
  border-radius: 999px;
  transition:
    color 0.16s ease,
    background 0.16s ease;
}

.header-link:hover {
  color: $color-primary;
  background: rgba(22, 93, 255, 0.06);
}

.header-register,
.mobile-action-btn.primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 34px;
  padding: 0 22px;
  margin-left: 4px;
  border: none;
  border-radius: 8px;
  background: #165dff;
  color: #ffffff;
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  box-shadow: 0 4px 12px rgba(22, 93, 255, 0.2);
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease,
    background 0.16s ease;
}

.header-register:hover,
.mobile-action-btn.primary:hover {
  transform: translateY(-1px);
  background: #0e4fcc;
  box-shadow: 0 8px 20px rgba(22, 93, 255, 0.28);
}

.mobile-action-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 40px;
  padding: 0 18px;
  border: 1px solid $border-color;
  border-radius: $sm-border-radius;
  background: $bg-color-card;
  color: $text-color-primary;
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  transition:
    background-color $motion-fast ease,
    border-color $motion-fast ease,
    box-shadow $motion-fast ease;
}

.mobile-menu-toggle {
  display: none;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  color: $text-color-primary;
  cursor: pointer;
  flex-shrink: 0;
  border: none;
  background: transparent;
  padding: 0;
}

.mobile-user-icon {
  display: none;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  color: #374151;
  text-decoration: none;
  flex-shrink: 0;
  border-radius: 50%;
  transition:
    color 0.16s ease,
    background 0.16s ease;
}

.mobile-user-icon.is-avatar {
  background: #165dff;
  color: #ffffff;
}

.mobile-user-icon:hover {
  color: $color-primary;
  background: rgba(22, 93, 255, 0.06);
}

.mobile-user-icon.is-avatar:hover {
  color: #ffffff;
  background: #0e4fcc;
}

.hamburger {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  width: 18px;
  height: 18px;
  gap: 4px;
}

.hamburger-line {
  display: block;
  width: 18px;
  height: 2px;
  background: currentColor;
  border-radius: 1px;
  transition:
    transform 0.3s ease,
    opacity 0.3s ease;
  transform-origin: center;
}

.mobile-menu-toggle.is-open {
  .hamburger-line {
    &:nth-child(1) {
      transform: translateY(6px) rotate(45deg);
    }

    &:nth-child(2) {
      opacity: 0;
      transform: scaleX(0);
    }

    &:nth-child(3) {
      transform: translateY(-6px) rotate(-45deg);
    }
  }
}

.mobile-menu-mask {
  position: fixed;
  top: 64px;
  right: 0;
  bottom: 0;
  left: 0;
  background: rgba(0, 0, 0, 0.24);
  z-index: 98;
}

.mobile-menu-panel {
  position: fixed;
  top: 64px;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 99;
  background: $bg-color-card;
  border-bottom: 1px solid $divider-color;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
  -ms-overflow-style: none;
}

.mobile-menu-panel::-webkit-scrollbar {
  display: none;
  width: 0;
  height: 0;
}

.mobile-menu-two-col {
  display: flex;
  min-height: 100%;
}

.mobile-menu-col--left {
  flex-shrink: 0;
  width: 120px;
  background: #f5f7fa;
  border-right: 1px solid $divider-color;
  padding: 8px 0 0;
  display: flex;
  flex-direction: column;
}

.mobile-first-level-item {
  position: relative;
  display: flex;
  align-items: center;
  min-height: 48px;
  padding: 0 16px;
  color: $text-color-secondary;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  transition:
    color 0.15s ease,
    background 0.15s ease;
}

.mobile-first-level-item.active {
  color: $color-primary;
  font-weight: 600;
  background: #ffffff;
}

.mobile-first-level-item.active::before {
  content: "";
  position: absolute;
  left: 0;
  top: 50%;
  transform: translateY(-50%);
  width: 3px;
  height: 16px;
  border-radius: 0 2px 2px 0;
  background: $color-primary;
}

.mobile-menu-col--right {
  flex: 1;
  min-width: 0;
  padding: 8px 16px 16px;
}

.mobile-second-group {
  margin-bottom: 4px;
}

.mobile-second-level-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  min-height: 48px;
  padding: 12px 4px;
  border: none;
  background: transparent;
  color: $text-color-primary;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  border-bottom: 1px solid $divider-color;
  transition:
    color 0.15s ease,
    background 0.15s ease;
}

.mobile-second-level-item.active {
  color: $color-primary;
}

.mobile-second-level-item:active {
  background: rgba(0, 0, 0, 0.04);
}

.mobile-second-level-count {
  font-size: 12px;
  color: $text-color-placeholder;
  font-weight: 400;
}

.mobile-second-level-right {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.mobile-second-level-arrow {
  color: #000000;
  transition: transform 0.2s ease;
  transform: rotate(0deg);
}

.mobile-second-level-arrow.expanded {
  transform: rotate(90deg);
}

.mobile-third-level-list {
  padding: 4px 0 8px 12px;
  background: #f9fafb;
  border-radius: 6px;
  margin: 4px 0 8px;
}

.mobile-third-level-item {
  display: block;
  padding: 8px 4px;
  color: $text-color-secondary;
  font-size: 13px;
  text-decoration: none;
  transition: color 0.15s ease;
}

.mobile-third-level-item:hover,
.mobile-third-level-item:active {
  color: $color-primary;
}

.mobile-third-level-item:active {
  background: rgba(0, 0, 0, 0.03);
  border-radius: 4px;
}

.mobile-third-level-item--link {
  padding: 10px 4px;
  border-bottom: 1px solid $divider-color;
}

.mobile-view-all {
  display: block;
  padding: 12px 4px 0;
  color: $color-primary;
  font-size: 13px;
  font-weight: 500;
  text-decoration: none;
}

.mobile-menu-loading {
  padding: 24px 0;
  text-align: center;
  color: $text-color-placeholder;
  font-size: 13px;
}

.mobile-menu-empty {
  padding: 24px 0;
  text-align: center;
  color: $text-color-placeholder;
  font-size: 13px;
}

.mobile-left-bottom {
  margin-top: auto;
  padding: 12px;
  border-top: 1px solid $divider-color;
}

.mobile-left-login-btn {
  display: block;
  width: 100%;
  padding: 8px 0;
  text-align: center;
  font-size: 13px;
  font-weight: 500;
  color: #ffffff;
  background: $color-primary;
  border-radius: 6px;
  text-decoration: none;
  transition: opacity 0.15s ease;
}

.mobile-left-login-btn:active {
  opacity: 0.85;
}

.mobile-action-btn {
  width: 100%;
  height: 40px;
}

.site-main {
  flex: 1;
  position: relative;
  z-index: 1;
}

.site-footer {
  margin-top: auto;
  padding: 56px 0 0;
  background: #f4f7fc;
  border-top: 1px solid $divider-color;
}

.footer-top {
  display: grid;
  grid-template-columns: minmax(260px, 320px) minmax(0, 1fr);
  gap: 64px;
  padding-bottom: 32px;
}

.footer-brand {
  min-width: 0;
}

.footer-brand__desc {
  margin-top: 16px;
  color: $text-color-secondary;
  font-size: 13px;
  line-height: 1.85;
}

.footer-logo {
  display: flex;
  align-items: center;
  min-height: 40px;
  min-width: 148px;
}

.footer-logo-image {
  display: block;
  width: 148px;
  height: 40px;
  max-width: 100%;
  object-fit: contain;
  object-position: left center;
}

.footer-logo-fallback {
  display: flex;
  align-items: center;
  height: 40px;
  padding: 0 10px;
  background: $color-primary;
  color: #fff;
  font-size: 16px;
  font-weight: 600;
  border-radius: 8px;
  white-space: nowrap;
}

.footer-contact {
  display: grid;
  gap: 8px;
  margin: 24px 0 0;
  padding: 0;
  list-style: none;
}

.footer-contact li {
  display: flex;
  align-items: center;
  gap: 12px;
  color: $text-color-secondary;
  font-size: 12px;
  line-height: 1.6;
}

.footer-contact__label {
  flex-shrink: 0;
  color: $text-color-placeholder;
  font-size: 12px;
}

.footer-contact__value {
  color: $text-color-primary;
  font-size: 13px;
  font-weight: 500;
}

.footer-contact__link {
  text-decoration: none;

  &:hover {
    color: $color-primary;
  }
}

.footer-columns {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 32px;
}

.footer-col {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}

.footer-col h4 {
  margin: 0 0 4px;
  color: $text-color-primary;
  font-size: 14px;
  font-weight: 600;
}

.footer-col a {
  color: $text-color-secondary;
  font-size: 13px;
  text-decoration: none;
  transition: color 0.16s ease;
}

.footer-col a:hover {
  color: $color-primary;
}

.footer-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px 24px;
  min-height: 56px;
  padding: 14px 0;
  border-top: 1px solid $divider-color;
  color: $text-color-placeholder;
  font-size: 12px;
}

.footer-bottom p {
  margin: 0;
}

.footer-bottom__meta {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 8px 16px;
  color: $text-color-placeholder;
}

.mobile-menu-mask-enter-active,
.mobile-menu-mask-leave-active,
.mobile-menu-panel-enter-active,
.mobile-menu-panel-leave-active {
  transition:
    opacity 0.16s ease,
    transform 0.16s ease;
}

.mobile-menu-mask-enter-from,
.mobile-menu-mask-leave-to,
.mobile-menu-panel-enter-from,
.mobile-menu-panel-leave-to {
  opacity: 0;
}

.mobile-menu-panel-enter-from,
.mobile-menu-panel-leave-to {
  transform: translateY(-4px);
}

@media (max-width: 1180px) {
  .header-link {
    display: none;
  }

  .main-nav__link {
    padding: 0 12px;
  }
}

@media (max-width: 960px) {
  .main-nav,
  .header-actions,
  .mega-menu {
    display: none;
  }

  .mobile-menu-toggle {
    display: inline-flex;
  }

  .mobile-user-icon {
    display: inline-flex;
  }

  .header-bar {
    gap: 12px;
  }

  .logo {
    margin-right: auto;
  }

  .footer-top {
    grid-template-columns: 1fr;
    gap: 32px;
  }

  .footer-columns {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px 32px;
  }

  .footer-bottom {
    justify-content: flex-start;
  }
}

@media (max-width: 640px) {
  .container {
    width: calc(100% - 24px);
  }

  .logo-image {
    max-width: 138px;
    height: 30px;
  }

  .logo-fallback {
    height: 30px;
    font-size: 13px;
    padding: 0 6px;
  }

  .footer-logo {
    min-width: 120px;
  }

  .footer-logo-image {
    width: 120px;
    height: 32px;
  }

  .footer-logo-fallback {
    height: 32px;
    font-size: 14px;
    padding: 0 8px;
  }

  .mobile-menu-col--right {
    padding-left: 12px;
    padding-right: 12px;
  }

  .mobile-left-bottom {
    padding: 8px;
    padding-bottom: calc(8px + env(safe-area-inset-bottom));
  }

  .footer-top {
    gap: 24px;
    padding-bottom: 24px;
  }

  .footer-columns {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px 16px;
  }

  .footer-col {
    min-width: 0;
  }

  .footer-bottom {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    padding-block: 18px;
  }

  .footer-bottom__meta {
    gap: 4px 14px;
  }
}

@media (max-width: 480px) {
  .footer-contact {
    gap: 6px;
  }

  .footer-contact li {
    gap: 8px;
  }

  .footer-columns {
    gap: 18px 12px;
  }

  .footer-col h4 {
    font-size: 13px;
  }

  .footer-col a {
    font-size: 12px;
  }
}
</style>

<style lang="scss">
.mobile-menu-panel::-webkit-scrollbar {
  display: none;
  width: 0;
  height: 0;
}
</style>
