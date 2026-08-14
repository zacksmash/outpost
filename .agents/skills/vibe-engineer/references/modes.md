# Vibe Engineering — Modes

The developer can shift how the workflow operates at any time. The default is always **Step Mode**.

## Step Mode (default)

One task at a time. Execute, report, suggest. This is the standard loop. After completing any other mode, always return to Step Mode.

## Flow Mode

The developer wants multiple tasks done at once. They might say:

- "do 1, 2, and 3"
- "handle all the migration and model stuff"
- "knock out the boilerplate"

Execute all the requested tasks sequentially. Reconcile and validate each task at its natural boundary, stack everything into the uncommitted batch, and report the group together. Then present 4 suggestions in the UI picker. Do not stop between tasks unless validation fails, workspace state diverges, or a blocking decision prevents safe continuation.

If a later task needs a blocking or hard-to-reverse decision, finish reconciling the current task, ask one decision question through `AskUserQuestion`, and wait. Do not edit the blocked task or the tasks after it. Resume only the remaining batch after the developer answers. For a reversible micro-decision, take the sensible default and report it instead of interrupting the batch.

After Flow Mode completes, return to Step Mode.

## Agent Mode

The developer wants you to keep going autonomously. They might say:

- "just keep going"
- "run through the plan"

Work through the plan on your own. After each logical chunk of work (2-4 related tasks), pause and present a summary of what you did plus the UI picker with 4 suggestions for the next chunk of work. The developer can type "approve" to commit and keep going, "stop" to pause agent mode, or pick a suggestion to redirect.

Do not treat the phrase "auto mode" alone as Agent Mode. Claude Code also uses **Auto** for a permission mode. Ask which meaning the developer intends when context does not make it clear.

Agent Mode pairs naturally with an active `/goal`: if a turn ends before the condition is met, the evaluator starts another turn with feedback. At every chunk boundary, call `AskUserQuestion` so the developer still gets the required picker checkpoint. A goal does not expand scope or replace developer choices.

In Step or Flow Mode, use `/goal` only when `askUserQuestionTimeout` is disabled. An active goal automatically restarts turns, while a timed-out picker tells Claude to continue without the developer; combining them can either bypass the checkpoint or create an unattended loop. If the timeout is enabled, recommend the normal definition of done without activating `/goal`. Never choose a suggestion merely because a goal started another turn.

**Never enter Agent Mode on your own.** Only the developer activates it.

## Ask Mode

Already built into the default behavior. If the developer asks a question, answer it without touching files. Present 4 suggestions in the UI picker informed by the answer.

## Pausing

The developer might say:

- "pause"
- "hold on"
- "I need to do something else"

Acknowledge and step back. The developer is now just using the interface normally. The conversation context is preserved. When they're ready, they can say "resume", "continue", "pick up where we left off", or just type an instruction, and you drop back into the vibe workflow — presenting the UI picker with 4 suggestions based on where they left off.
