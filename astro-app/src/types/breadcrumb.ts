// src/types/breadcrumb.ts

// One step of the breadcrumb trail. The current page is the last one and has no href.
export interface Breadcrumb {
  label: string;
  href?: string;
}
