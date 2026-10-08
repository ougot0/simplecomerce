"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

export function NavLinks({ items }: { items: { href: string; label: string; count?: string; exact?: boolean }[] }) {
  const path = usePathname();
  return (
    <ul className="nav">
      {items.map((item) => {
        const active = item.exact ? path === item.href : path === item.href || path.startsWith(`${item.href}/`);
        return (
          <li key={item.href}>
            <Link href={item.href} aria-current={active ? "page" : undefined}>
              <span>{item.label}</span>
              {item.count && <span className="count">{item.count}</span>}
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
