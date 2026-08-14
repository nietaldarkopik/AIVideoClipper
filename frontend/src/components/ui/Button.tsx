import { ButtonHTMLAttributes, forwardRef } from "react";
import { clsx } from "clsx";
import { Loader2 } from "lucide-react";

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: "primary" | "secondary" | "ghost" | "danger" | "outline";
  size?: "sm" | "md" | "lg";
  loading?: boolean;
}

const variants: Record<string, string> = {
  primary:
    "bg-accent text-white hover:brightness-110 shadow-[0_0_0_1px_rgba(124,92,255,0.4)]",
  secondary: "bg-surface-elevated text-foreground hover:bg-white/10 border border-border-subtle",
  ghost: "text-foreground hover:bg-white/5",
  danger: "bg-danger text-white hover:brightness-110",
  outline: "border border-border-subtle text-foreground hover:bg-white/5",
};

const sizes: Record<string, string> = {
  sm: "text-xs px-2.5 py-1.5 gap-1.5 rounded-lg",
  md: "text-sm px-3.5 py-2 gap-2 rounded-xl",
  lg: "text-sm px-5 py-2.5 gap-2 rounded-xl",
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant = "primary", size = "md", loading, disabled, children, ...props }, ref) => {
    return (
      <button
        ref={ref}
        disabled={disabled || loading}
        className={clsx(
          "inline-flex items-center justify-center font-medium transition-all disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer whitespace-nowrap",
          variants[variant],
          sizes[size],
          className
        )}
        {...props}
      >
        {loading && <Loader2 className="size-4 animate-spin" />}
        {children}
      </button>
    );
  }
);
Button.displayName = "Button";
