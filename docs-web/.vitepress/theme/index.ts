import DefaultTheme from "vitepress/theme";
import Layout from "./Layout.vue";
import DeploymentSelector from "./components/DeploymentSelector.vue";
import "./style.css";

export default {
  extends: DefaultTheme,
  Layout,
  enhanceApp({ app }) {
    app.component("DeploymentSelector", DeploymentSelector);
  },
};
