import * as ProgressPrimitive from "@radix-ui/react-progress"
import * as React from "react"

import { cn } from "@/lib/utils"

function Progress({
  className,
  indicatorClassName,
  value,
  ...props
}: React.ComponentProps<typeof ProgressPrimitive.Root> & { indicatorClassName?: string }) {
  return (
    <ProgressPrimitive.Root
      data-slot="progress"
      value={value}
      className={cn("bg-muted relative h-2 w-full overflow-hidden rounded-full", className)}
      {...props}
    >
      <ProgressPrimitive.Indicator
        data-slot="progress-indicator"
        className={cn("bg-primary h-full rounded-full transition-[width] duration-700 ease-out", indicatorClassName)}
        style={{ width: `${Math.max(0, Math.min(100, value ?? 0))}%` }}
      />
    </ProgressPrimitive.Root>
  )
}

export { Progress }
