import type { InjectionKey } from 'vue';
import { inject, provide } from 'vue';

import type { useServiceConsole } from '@/domains/services/useServiceConsole';

export type ServiceConsoleContext = ReturnType<typeof useServiceConsole>;

const SERVICE_CONSOLE_CONTEXT: InjectionKey<ServiceConsoleContext> = Symbol('service-console-context');

export function provideServiceConsoleContext(context: ServiceConsoleContext) {
  provide(SERVICE_CONSOLE_CONTEXT, context);
}

export function useServiceConsoleContext(): ServiceConsoleContext {
  const context = inject(SERVICE_CONSOLE_CONTEXT);

  if (!context) {
    throw new Error('Service console context is not available.');
  }

  return context;
}
