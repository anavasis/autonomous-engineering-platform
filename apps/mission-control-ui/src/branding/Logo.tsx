export function LogoMark({ size = 36 }: { size?: number }) {
  return (
    <svg
      className="aep-brand-mark"
      width={size}
      height={size}
      viewBox="0 0 64 64"
      fill="none"
      aria-hidden="true"
    >
      <rect width="64" height="64" rx="14" fill="#121821" stroke="#243041" />
      <path d="M18 40L32 14L46 40H18Z" stroke="#5EEAD4" strokeWidth="3.5" strokeLinejoin="round" />
      <circle cx="32" cy="34" r="3.5" fill="#F59E0B" />
      <path d="M22 46H42" stroke="#94A3B8" strokeWidth="2.5" strokeLinecap="round" />
    </svg>
  );
}
