import type { Browser } from '@e2e-dev/web';

export async function setModel(browser: Browser, key: string, value: string | number, live = false) {
  const selector = `[wire\\:model="${key}"], [wire\\:model\\.live="${key}"], [wire\\:model\\.live\\.debounce\\.300ms="${key}"]`;
  const target = browser.locator(selector).first();
  await target.waitFor({ state: 'attached' });
  const state = await browser.evaluate(query => {
    const element = document.querySelector<HTMLElement>(query);
    if (!element) throw new Error('Maintenance form model is missing.');
    const details = element.closest('details');
    if (details) details.open = true;
    return { visible: element.getClientRects().length > 0, tag: element.tagName.toLowerCase() };
  }, selector);
  if (live) {
    await browser.evaluate(async ([query, name, next]) => {
      const element = document.querySelector<HTMLElement>(query);
      const component = element?.closest<HTMLElement>('[wire\\:id]');
      const id = component?.getAttribute('wire:id');
      const livewire = (window as Window & { Livewire?: { find(id: string): { $set(name: string, value: string, live: boolean): Promise<unknown> } } }).Livewire;
      if (!id || !livewire) throw new Error('Maintenance Livewire component is unavailable.');
      await livewire.find(id).$set(name, next, true);
    }, [selector, key, String(value)]);
    return;
  }
  if (state.visible) {
    if (state.tag === 'select') await target.selectOption({ value: String(value) });
    else await target.fill(String(value));
    return;
  }
  await browser.evaluate(async ([query, name, next]) => {
    const element = document.querySelector<HTMLElement>(query);
    const component = element?.closest<HTMLElement>('[wire\\:id]');
    const id = component?.getAttribute('wire:id');
    const livewire = (window as Window & { Livewire?: { find(id: string): { $set(name: string, value: string, live: boolean): Promise<unknown> } } }).Livewire;
    if (!id || !livewire) throw new Error('Maintenance Livewire component is unavailable.');
    await livewire.find(id).$set(name, next, false);
  }, [selector, key, String(value)]);
}

export async function setLivewireModel(browser: Browser, selector: string, key: string, value: string) {
  await browser.locator(selector).waitFor({ state: 'attached' });
  await browser.evaluate(async ([query, name, next]) => {
    const input = document.querySelector<HTMLElement>(query);
    const component = input?.closest<HTMLElement>('[wire\\:id]');
    const id = component?.getAttribute('wire:id');
    const livewire = (window as Window & { Livewire?: { find(id: string): { $set(name: string, value: string, live: boolean): Promise<unknown> } } }).Livewire;
    if (!id || !livewire) throw new Error('Maintenance Livewire component is unavailable.');
    await livewire.find(id).$set(name, next, true);
  }, [selector, key, value]);
}
